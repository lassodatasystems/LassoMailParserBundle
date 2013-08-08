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

use LogicException;
use Zend\Mail\Storage\Part;

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

    protected $partFactory;

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
    }

    /**
     * Returns the mail object. $this->parse() has to be called before this function is available.
     *
     * @return null|\Zend\Mail\Storage\Part
     * @throws \LogicException
     */
    public function getMail()
    {
        if (empty($this->mail)) {
            throw new LogicException('You must first call $this->parse()');
        }

        return $this->mail;
    }

    /**
     * Returns all email addresses contained in the email headers. This includes, to, from, cc, and bcc.
     * $this->parse() has to be called before this function is available.
     *
     * @return array
     * @throws \LogicException
     */
    public function getAllEmailAddresses($fields = ['to', 'from', 'cc', 'bcc'])
    {
        if (empty($this->mail)) {
            throw new LogicException('You must first call $this->parse()');
        }

        $getAddresses = function($field, $mail) {
            $addresses = [];
            try {
                foreach ($mail->getHeader($field)->getAddressList() as $address) {
                    $addresses[] = $address->getEmail();
                }
            } catch (\Exception $e) {
            }

            return $addresses;
        };

        $addresses = [];
        foreach ($fields as $field) {
            $addresses = array_merge($addresses, $getAddresses($field, $this->mail));
        }

        $addresses = array_unique($addresses);

        return $addresses;
    }

    /**
     * @param Part $part
     *
     * @return Part[]
     */
    protected function flattenParts(Part $part)
    {
        $parts = [];
        for ($i = 1; $i <= $part->countParts(); ++$i) {
            $newPart = $part->getPart($i);

            if ($newPart->isMultipart()) {
                $parts = array_merge($parts, $this->flattenParts($newPart));
            } elseif ($newPart->getHeaders()->has('Content-Type') && $newPart->getHeaders()->get('Content-Type')->getType() == 'message/rfc822') {
                $newPart = $this->partFactory->getPart($newPart->getContent());

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
        if (isset($part->content_transfer_encoding)) {
            $contentTransferEncoding = $part->content_transfer_encoding;
        }

        switch ($contentTransferEncoding) {
            case 'base64':
                $content = base64_decode($part->getContent());
                break;

            case 'quoted-printable':
                $content = quoted_printable_decode($part->getContent());
                break;

            default:
                $content = $part->getContent();
                break;
        }

        return trim($content);
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
            $glue = function() { return ''; };
        }

        if ($this->getMail()->isMultipart()) {
            $parts = $this->flattenParts($this->getMail());
        } else {
            $parts = [$this->getMail()];
        }

        $textContent = [];
        $htmlContent = [];

        foreach ($parts as $part) {
            $contentType = 'text/plain';
            if ($part->getHeaders()->has('Content-Type')) {
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
        $combineParts = function($parts, $contentType) use ($glue) {
            $first = array_shift($parts);
            return array_reduce(
                $parts,
                function($soFar, $part) use ($glue, $contentType) {
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
