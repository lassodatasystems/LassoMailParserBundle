<?php
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

        return $content;
    }

    public function getPrimaryContent()
    {
        if ($this->getMail()->isMultipart()) {
            $parts = $this->flattenParts($this->getMail());

            $textContent = null;
            $htmlContent = null;

            foreach ($parts as $part) {
                $contentType = $part
                    ->getHeader('Content-Type')
                    ->getType();
                if ($contentType == 'text/plain') {
                    $textContent = $this->decodeBody($part);
                }
                if ($contentType == 'text/html') {
                    $htmlContent = $this->decodeBody($part);
                }
            }
        } else {
            if ($this->getMail()->getHeader('Content-Type')->getType() == 'text/plain') {
                $textContent = $this->decodeBody($this->getMail());
            }
            if ($this->getMail()->getHeader('Content-Type')->getType() == 'text/html') {
                $htmlContent = $this->decodeBody($this->getMail());
            }
        }

        if (!empty($htmlContent)) {
            return trim($htmlContent);
        }

        if (!empty($textContent)) {
            return trim($textContent);
        }

        return null;
    }
}
