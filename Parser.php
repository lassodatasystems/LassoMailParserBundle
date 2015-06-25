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
use Lasso\MailParserBundle\PartTree\PartTreeFactory;
use Zend\Mail\Exception\InvalidArgumentException;
use Zend\Mail\Header\AbstractAddressList;
use Zend\Mail\Header\HeaderInterface;
use Zend\Mail\Storage\Part;
use Zend\Mime\Exception\RuntimeException;

/**
 * Provides a very simple wrapper around the zend mail library. Contains assorted helper functions regarding mail
 * processing.
 */
class Parser extends ParseHelper
{
    /**
     * @var PartFactory
     */
    private $partFactory;

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
     * @return ParsedMail
     */
    public function parse($mail)
    {
        // Initial
        /** @var Part $mailPart */
        $mailPart = $this->workAroundMissingBoundary(
            $this->partFactory->getPart($mail),
            $mail
        );

        $partTreeFactory = new PartTreeFactory($this->partFactory);
        $partTree = $partTreeFactory->make($mailPart);

        /** @var Part[] $parts */
        $parts = $partTree->flattenParts();

        /** @var Part $envelopedEmail */
        $envelopedEmail = $partTree->getEnvelopedEmail();

        // Everything else
        $loggingEmails = $this->getLoggingEmails($mailPart, $parts);
        // All email addresses as a hash in the form $allEmailAddressesByField[field] = [];
        $allEmailAddressesByField = $this->getAllEmailAddresses($parts);

        return new ParsedMail(
            $mail,
            $mailPart,
            $parts,
            $loggingEmails,
            $allEmailAddressesByField,
            $envelopedEmail
        );
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
     * @return Part
     */
    private function workAroundMissingBoundary(Part $part, $rawMailBody)
    {
        try {
            $part->countParts();
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

                $part = $this->partFactory->getPart($content);
            }
        }

        return $part;
    }



    /**
     * Returns a list of all emails in the parser, including
     * the message id. This is mostly useful for logging.
     *
     * @param Part   $mail
     * @param Part[] $parts
     *
     * @return array
     */
    private function getLoggingEmails($mail, $parts)
    {
        $emailAddresses = $this->flattenEmailAddresses(
            $this->getAllEmailAddresses($parts)
        );

        $messageIds = [];
        $headers    = $mail->getHeaders();
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
     * Takes the hash of email address and the field they were found in and
     * returns a flattened array of those email addresses.
     *
     * @param $emailAddressesByField
     *
     * @return array
     */
    private function flattenEmailAddresses($emailAddressesByField) {
        $flattened = [];
        foreach ($emailAddressesByField as $field => $emailAddresses) {
            foreach ($emailAddresses as $emailAddress) {
                $flattened[] = $emailAddress;
            }
        }

        return array_unique($flattened);
    }

    /**
     * Returns all email addresses contained in the email headers. This includes, to, from, cc, and bcc.
     *
     * @param Part[] $parts
     * @param array  $fields
     *
     * @return array
     */
    private function getAllEmailAddresses($parts, $fields = ['to', 'from', 'cc', 'bcc'])
    {
        $addresses = [];
        foreach ($fields as $field) {
            $addressesPerField = [];
            foreach ($parts as $part) {
                 $addressesPerField = array_merge(
                     $addressesPerField,
                     $this->getAddressesFromFieldInPart($field, $part)
                 );
            }
            $addresses[$field] = $addressesPerField;
        }

        return $addresses;
    }

    /**
     * Retrieves the addresses from a specific field in a part
     *
     * @param string $field
     * @param Part   $part
     *
     * @return array
     */
    private function getAddressesFromFieldInPart($field, Part $part)
    {
        $addresses = [];

        $headers = $part->getHeaders();
        if (empty($headers)) {
            return $addresses;
        }

        if (!$headers->has($field)) {
            return $addresses;
        }

        /** @var AbstractAddressList $addressList */
        $addressList = null;

        try {
            $addressList = $part->getHeader($field);
        } catch (InvalidArgumentException $e) {
            return $addresses;
        }

        if (!$addressList instanceof AbstractAddressList) {
            return $addresses;
        }

        foreach ($addressList->getAddressList() as $address) {
            $addresses[] = strtolower($address->getEmail());
        }

        return $addresses;
    }
}
