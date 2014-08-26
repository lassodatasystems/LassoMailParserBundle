<?php
/*
 * Copyright 2013 Lasso Data Systems
 *
 * This file is part of LassoMailParser.
 *
 * LassoMailParser is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * LassoMailParser is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with LassoMailParser. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace Lasso\MailParserBundle;

use ArrayIterator;
use Exception;
use LogicException;
use UnexpectedValueException;
use Zend\Mail\Header\AbstractAddressList;
use Zend\Mail\Header\HeaderInterface;
use Zend\Mail\Storage\Part;
use Zend\Mime\Exception\RuntimeException;

/**
 * Provides a very simple wrapper around the zend mail library. Contains assorted helper functions regarding mail
 * processing.
 */
class Parser
{
    /**
     * @var Part
     */
    protected $mail = null;

    /**
     * Linear array of all parts in the email. The parts of enveloped emails will also be in here
     *
     * @var Part[]
     */
    protected $parts = [];

    /**
     * @var PartFactory
     */
    protected $partFactory;

    /**
     * @var Part
     */
    protected $envelopedEmail;

    /**
     * @var Array
     */
    protected $knownCharsets;

    /**
     * Keeps a list of parts that produced charset problems while decoding the body
     *
     * @var array
     */
    protected $problematicParts = [];

    /**
     * Identifies exception for unknown charsets
     *
     * @var int
     */
    const INVALID_CHARSET_ERROR_CODE = 525;

    /**
     * @param PartFactory $partFactory
     */
    public function __construct(PartFactory $partFactory)
    {
        $this->partFactory = $partFactory;

        $this->knownCharsets = array_map([$this, 'prepareEncodingName'], mb_list_encodings());
    }

    /**
     * Accepts the raw email as a string, including headers and body. Has to be called before the other functions
     * are available.
     *
     * @param string $mail
     */
    public function parse($mail)
    {
        $this->mail = $this->partFactory->getPart($mail);

        $this->workAroundMissingBoundary($this->mail, $mail);

        $this->parts = $this->flattenParts($this->mail);
    }

    /**
     * Some emails are missing a MIME closing boundary. This violations the standard,
     * but most software still handles it well - except the Zend Framework, who rather
     * don't want to accommodate broken emails.
     *
     * Since they don't provide any meaningful way to test if the message was decoded
     * correctly (like a $part->test() method or something), the only recourse is to
     * call some function on the part and handle the exception that's thrown when the
     * closing boundary is missing.
     *
     * Then append the boundary and re-parse the email.
     *
     * @param Part   $part
     * @param string $rawMailBody
     */
    public function workAroundMissingBoundary(Part $part, $rawMailBody)
    {
        try {
            $this->mail->countParts();
        } catch (RuntimeException $e) {
            if (count($part->getHeaders()) > 0
                && $part->getHeaders()->has('Content-Type')
                && array_key_exists('boundary', $this->getContentType($part)->getParameters())
            ) {
                $boundary = $part
                    ->getHeaders()
                    ->get('Content-Type')
                    ->getParameter('boundary');

                $content = trim($rawMailBody);
                $content .= "\n--{$boundary}--";

                $this->mail = $this->partFactory->getPart($content);
            }
        }
    }

    /**
     * Returns the mail object. $this->parse() has to be called before this function is available.
     *
     * @return null|Part
     * @throws LogicException
     */
    public function getMail()
    {
        if (empty($this->mail)) {
            throw new LogicException('You must first call $this->parse()');
        }

        return $this->mail;
    }

    /**
     * Retrieves the addresses from a specific field in a part
     *
     * @param string $field
     * @param Part   $part
     *
     * @return array
     */
    protected function getAddressesFromFieldInPart($field, Part $part)
    {
        $addresses = [];

        $headers = $part->getHeaders();
        if (empty($headers)) {
            return $addresses;
        }

        if (!$headers->has($field)) {
            return $addresses;
        }

        $addressList = $part->getHeader($field);
        if (!$addressList instanceof AbstractAddressList) {
            return $addresses;
        }

        foreach ($addressList->getAddressList() as $address) {
            $addresses[] = strtolower($address->getEmail());
        }

        return $addresses;
    }

    /**
     * Returns all email addresses contained in the email headers. This includes, to, from, cc, and bcc.
     * $this->parse() has to be called before this function is available.
     *
     * @param array $fields
     *
     * @throws \LogicException
     * @return array
     */
    public function getAllEmailAddresses($fields = ['to', 'from', 'cc', 'bcc'])
    {
        if (empty($this->mail)) {
            throw new LogicException('You must first call $this->parse()');
        }

        $parts = array_merge([$this->mail], $this->parts);

        $addresses = [];
        foreach ($fields as $field) {
            foreach ($parts as $part) {
                $addresses = array_merge($addresses, $this->getAddressesFromFieldInPart($field, $part));
            }
        }

        $addresses = array_unique($addresses);

        return $addresses;
    }

    /**
     * Returns a list of all emails in the parser, including
     * the message id. This is mostly useful for logging.
     *
     * @return array
     * @throws \LogicException
     */
    public function getLoggingEmails()
    {
        if (empty($this->mail)) {
            throw new LogicException('You must first call $this->parse()');
        }

        $emailAddresses = $this->getAllEmailAddresses();

        $messageIds = [];
        $headers    = $this->mail->getHeaders();
        if (!empty($headers) && $headers->has('message-id')) {
            $messageId = $headers->get('message-id');
            if ($messageId instanceof ArrayIterator) {
                foreach ($messageId as $header) {
                    /** @var $header HeaderInterface */
                    $messageIds[] = $header->getFieldValue();
                }
            } elseif ($messageId instanceof HeaderInterface) {
                $messageIds = [$messageId->getFieldValue()];
            }
        }

        /*
         * Also strip < and > from message id to only return the email-like id
         */
        $messageIds = array_map(function ($messageId) {
            return trim($messageId, " \t\n\r\0\x0B<>");
        }, $messageIds);

        return array_merge($emailAddresses, $messageIds);
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
     * @param Part $part
     *
     * @return bool
     */
    protected function isEnvelopedEmail(Part $part)
    {
        if (!$this->hasHeader($part, 'Content-Type')) {
            return false;
        }

        return $this->getContentType($part)->getType() == 'message/rfc822';
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
                $contentType = $this
                    ->getContentType($part)
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
            throw new UnexpectedValueException('Content: ' . $content, Parser::INVALID_CHARSET_ERROR_CODE);
        }

        return trim($convertedContent);
    }

    /**
     * @param Part $part
     *
     * @return Part[]
     */
    protected function flattenParts(Part $part)
    {
        $parts = [];

        /*
         * $part->countParts(); can throw an error if the headers are missing.
         * Return an empty array if the headers are indeed missing.
         */
        if (count($part->getHeaders()) === 0) {
            return $parts;
        }

        try {
            $partCount = $part->countParts();
        } catch (Exception $e) {
            return $parts;
        }

        for ($i = 1; $i <= $partCount; ++$i) {
            $newPart = $part->getPart($i);

            if (count($newPart->getHeaders()) === 0) {
                continue;
            }

            if ($newPart->isMultipart()) {
                $parts = array_merge($parts, $this->flattenParts($newPart));
            } elseif ($this->isEnvelopedEmail($newPart)) {
                $newPart = $this->partFactory->getPart($newPart->getContent());

                /*
                 * This should be somewhere else, but:
                 * The parsed part has content-type 'multipart/alternative'.
                 * The parent part has 'message/rfc822', but relations between
                 * parts are not tracked. Therefore, I can't identify this
                 * part as the enveloped part anywhere but here.
                 *
                 * The parts should be in a tree structure, then it would be
                 * simple to identify this email after all parts were parsed.
                 */
                $this->envelopedEmail = $newPart;

                $parts[] = $newPart;

                $parts = array_merge($parts, $this->flattenParts($newPart));
            } else {
                $parts[] = $newPart;
            }
        }

        return $parts;
    }

    /**
     * The confusing zend API makes a custom function for
     * header checking necessary.
     *
     * @param Part   $part
     * @param string $header
     *
     * @return bool
     */
    protected function hasHeader(Part $part, $header)
    {
        if (count($part->getHeaders()) < 1) {
            return false;
        }

        return $part
            ->getHeaders()
            ->has($header);
    }

    /**
     * Returns the content type of the given part. Since zends API
     * allows for four different return values, we need to handle
     * every type differently. Multiple content-type headers can't
     * be accounted for, in those cases we simply take the first
     * one and hope for the best. If it doesn't work, there's not
     * much that could be done as content-type guessing is not an
     * easily solved problem.
     *
     * The content-type header should always be broken up into
     * a header interface, it just could happen that the zend
     * framework returns an array or array iterator when multiple
     * content-type headers are present. In that case, the first
     * encountered header will be used.
     *
     * @param Part $part
     *
     * @return HeaderInterface
     * @throws \Exception
     */
    protected function getContentType(Part $part)
    {
        if (!$this->hasHeader($part, 'Content-Type')) {
            throw new Exception('This email does not have a content type. Check for that with hasContentType()');
        }

        $zendContentType = $part
            ->getHeaders()
            ->get('Content-Type');

        switch (true) {
            case is_array($zendContentType):
                return $zendContentType[0];
            case $zendContentType instanceof HeaderInterface:
                return $zendContentType;
            case $zendContentType instanceof ArrayIterator:
                return $zendContentType->current();
            default:
                throw new Exception('Unexpected return type ' .
                    gettype($zendContentType) .
                    ', expected one of string, array, HeaderInterface or ArrayIterator' .
                    ' from Part::getHeaders()::get("Content-Type"))'
                );
        }
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
