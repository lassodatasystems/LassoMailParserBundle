<?php
namespace Lasso\MailParserBundle;

use ArrayIterator;
use Exception;
use UnexpectedValueException;
use Zend\Mail\Storage\Part;

class ParsedMail extends ParseHelper {
    private $rawMail;
    /** @var Part */
    private $mail;
    /**
     * Linear array of all parts in the email. The parts of enveloped emails will also be in here
     *
     * @var Part[]
     */
    private $parts = [];
    /**
     * Keeps a list of parts that produced charset problems while decoding the body
     *
     * @var array
     */
    private $problematicParts = [];
    /** @var Part */
    private $envelopedEmail = null;
    /** @var array */
    private $knownCharsets;
    /** @var array */
    private $allEmailAddressesByField;
    /** @var array */
    private $loggingEmails;

    /**
     * Identifies exception for unknown charsets
     *
     * @var int
     */
    const INVALID_CHARSET_ERROR_CODE = 525;

    function __construct(
        $rawMail,
        Part $mail,
        $parts,
        $loggingEmails,
        $allEmailAddressesByField,
        $envelopedEmail
    ) {
        $this->rawMail = $rawMail;
        $this->mail = $mail;
        $this->parts = $parts;
        $this->loggingEmails = $loggingEmails;
        $this->allEmailAddressesByField = $allEmailAddressesByField;
        $this->envelopedEmail = $envelopedEmail;
        $this->knownCharsets = array_map([$this, 'prepareEncodingName'], mb_list_encodings());
    }

    public function getRawMail()
    {
        return $this->rawMail;
    }

    /**
     * @return Part
     */
    public function getMail()
    {
        return $this->mail;
    }

    /**
     * Concatenates all the parts of an email. Will concatenate
     * html if there were html parts, else the text parts
     * are concatenated. Will not return any other parts (such as file attachments).
     *
     * The callable $glue, if given, will be called when
     * concatenating parts like this:
     *
     * $partOne . $glue($contentType) . $partTwo
     *
     * $glue needs to return a string. Using a functions allows to
     * return different values for different content types, e.g. <hr />
     * for html content.
     *
     * The content type of the parts will be passed in, like "text/html"
     * or "text/plain".
     *
     * @param callable $glue
     *
     * @return null|string
     */
    public function getPrimaryContent(Callable $glue = null)
    {
        /*
         * Simple no-op function. No if-statements necessary later.
         */
        if (empty($glue)) {
            $glue = function () {
                return '';
            };
        }

        if ($this->getMail()->isMultipart()) {
            $parts = $this->parts;
        } else {
            $parts = [$this->getMail()];
        }

        $textContent = [];
        $htmlContent = [];

        foreach ($parts as $part) {
            $contentType = 'text/plain';

            if ($this->hasHeader($part, 'Content-Type')) {
                $contentType = $this->getContentType($part)
                    ->getType();
            }

            try {
                if ($contentType == 'text/plain') {
                    $textContent[] = $this->decodeBody($part);
                }
                if ($contentType == 'text/html') {
                    $htmlContent[] = $this->decodeBody($part);
                }
            } catch (UnexpectedValueException $exception) {
                if ($exception->getCode() == self::INVALID_CHARSET_ERROR_CODE) {
                    $this->problematicParts[] = $part;

                    /*
                     * Couldn't convert content, in all likelihood can't work with it, so
                     * return an empty string
                     */
                    return "";
                }
            }
        }

        /**
         * Takes an array of parts and combines them with the glue
         * function. With a foreach loop, there's a need to track
         * whether the current part is the last part so $glue isn't
         * called after the last part.
         *
         * Using array_reduce() makes the last-part-tracking unnecessary.
         *
         * @param $parts
         * @param $contentType
         *
         * @return mixed
         */
        $combineParts = function ($parts, $contentType) use ($glue) {
            $first = array_shift($parts);

            return array_reduce(
                $parts,
                function ($soFar, $part) use ($glue, $contentType) {
                    return $soFar . $glue($contentType) . $part;
                },
                $first
            );
        };

        if (!empty($htmlContent)) {
            return $combineParts($htmlContent, 'text/html');
        }

        if (!empty($textContent)) {
            return $combineParts($textContent, 'text/plain');
        }

        return null;
    }

    /**
     * Returns all email addresses contained in the email headers. This includes, to, from, cc, and bcc.
     *
     * @param array $fields
     *
     * @return array
     */
    public function getAllEmailAddresses($fields = ['to', 'from', 'cc', 'bcc'])
    {
        $addresses = [];
        foreach ($fields as $field) {
            if(isset($this->allEmailAddressesByField[$field])) {
                foreach ($this->allEmailAddressesByField[$field] as $emailAddress) {
                    $addresses[] = $emailAddress;
                }
            }
        }

        return array_unique($addresses);
    }

    /**
     * Returns a list of all emails in the parser, including
     * the message id. This is mostly useful for logging.
     *
     * @return array
     */
    public function getLoggingEmails()
    {
        return $this->loggingEmails;
    }

    /**
     * If the email contained an enveloped email, this method will provide the enveloped email. It can
     * then be used to extract information about the original exchange.
     *
     * @return Part
     */
    public function getEnvelopedEmail()
    {
        return $this->envelopedEmail;
    }

    /**
     * Check whether an enveloped email was found
     *
     * @return bool
     */
    public function hasEnvelopedEmail()
    {
        return !empty($this->envelopedEmail);
    }

    /**
     * Returns all parts that had charset problems while decoding the content
     *
     * @return Part[]
     */
    public function getProblematicParts()
    {
        return $this->problematicParts;
    }

    /**
     * Check if there were any parts with charset problems
     *
     * @return bool
     */
    public function hasProblematicParts()
    {
        return !empty($this->problematicParts);
    }

    /**
     * @param Part $part
     *
     * @return string
     * @throws \UnexpectedValueException
     */
    private function decodeBody(Part $part)
    {
        $content = '';

        $contentTransferEncoding = '7-bit';
        $contentCharset = 'auto';
        $headers                 = $part->getHeaders();
        if (!empty($headers)) {
            if ($headers->has('Content-Transfer-Encoding')) {
                $contentTransferEncodingHeader = $headers->get('Content-Transfer-Encoding');
                if (is_a($contentTransferEncodingHeader, 'ArrayIterator')) {
                    /*
                     * Multiple transfer encoding headers don't really make sense and are
                     * indicative of a malformed message. Just choose the first one and hope
                     * it works.
                     */
                    $contentTransferEncodingHeader = $headers->get('Content-Transfer-Encoding')[0];
                }

                $contentTransferEncoding = $contentTransferEncodingHeader->getFieldValue();
            }

            if ($this->hasHeader($part, 'Content-Type')) {
                $newContentCharset = $this
                    ->getContentType($part)
                    ->getParameter('charset');


                if (!empty($newContentCharset)
                    && in_array($this->prepareEncodingName($newContentCharset), $this->knownCharsets)
                ) {
                    $contentCharset = $newContentCharset;
                }
            }
        }

        switch ($contentTransferEncoding) {
            case 'base64':
                $content = base64_decode($part->getContent());
                break;

            case 'quoted-printable':
                $content = quoted_printable_decode($part->getContent());
                break;

            default:
                try {
                    $content = $part->getContent();
                } catch (Exception $e) {
                    /*
                     * do nothing, email has not content, there is not function
                     * to check if the email is empty and $content is already
                     * set to an empty string
                     */
                }

                break;
        }

        /*
         * mb_convert_encoding might produce warnings/error if the $contentCharset is wrong.
         * mb_check_encoding for some reason doesn't fail those cases, so there's no way
         * to check if the encoding is correct.
         *
         * Using a custom error handler allows marking the part as problematic when
         * mb_convert_encoding produces a warning, while preventing a php-internal warning.
         *
         * This way, log files won't get cluttered and there's an easy way to deal with the
         * problematic parts.
         */
        $hasError = false;

        set_error_handler(function($errorLevel, $errorMessage) use (&$hasError) {
            $hasError = true;

            return true;
        }, E_ALL);

        $convertedContent = mb_convert_encoding($content, 'UTF-8', $contentCharset);

        restore_error_handler();

        if ($hasError) {
            throw new UnexpectedValueException('Content: ' . $content, ParsedMail::INVALID_CHARSET_ERROR_CODE);
        }

        return trim($convertedContent);
    }

    /**
     * Prepares an encoding name for lookup with php's internal functions
     *
     * @param string $name
     *
     * @return string
     */
    protected function prepareEncodingName($name)
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    }
}
