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
     * @param PartFactory $partFactory
     */
    public function __construct(PartFactory $partFactory)
    {
        $this->partFactory = $partFactory;
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
     */
    public function workAroundMissingBoundary(Part $part, $rawMailBody)
    {
        try {
            $this->mail->countParts();
        } catch (RuntimeException $e) {
            if (count($part->getHeaders()) > 0
                && $part->getHeaders()->has('Content-Type')
                && array_key_exists('boundary', $part->getHeaders()->get('Content-Type')->getParameters())
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
            $addresses[] = $address->getEmail();
        }

        return $addresses;
    }

    /**
     * Returns all email addresses contained in the email headers. This includes, to, from, cc, and bcc.
     * $this->parse() has to be called before this function is available.
     *
     * @return array
     * @throws LogicException
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
     * @param Parser $email
     *
     * @return array
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
        $headers = $part->getHeaders();
        if (empty($headers)) {
            return false;
        }

        return $headers->has('Content-Type')
        && $headers->get('Content-Type')->getType() == 'message/rfc822';
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
     * @param Part $message
     */
    private function decodeBody(Part $part)
    {
        $content = '';

        $contentTransferEncoding = '7-bit';
        $contentCharset = 'auto';
        $headers                 = $part->getHeaders();
        if (!empty($headers)) {
            if ($headers->has('Content-Transfer-Encoding')) {
                $contentTransferEncoding = $headers
                    ->get('Content-Transfer-Encoding')
                    ->getFieldValue();
            }

            if ($headers->has('Content-Type')) {
                $newContentCharset = $headers->get('Content-Type')->getParameter('charset');

                if (!empty($newContentCharset)) {
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

        return trim(mb_convert_encoding($content, 'UTF-8', $contentCharset));
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
            $headers     = $part->getHeaders();
            if (!empty($headers)
                && $headers->has('Content-Type')
            ) {
                $contentType = $part->getHeader('Content-Type')
                    ->getType();
            }

            if ($contentType == 'text/plain') {
                $textContent[] = $this->decodeBody($part);
            }
            if ($contentType == 'text/html') {
                $htmlContent[] = $this->decodeBody($part);
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
}
