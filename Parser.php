<?php
namespace Lasso\MailParserBundle;

use Zend\Mail\Storage\Part;

class Parser
{
    /**
     * @var Part
     */
    protected $mail = null;

    /**
     * Accepts the raw email as a string, including headers and body. Has to be called before the other functions
     * are available.
     *
     * @param string $mail
     */
    public function parse($mail)
    {
        $this->mail = new Part(['raw' => $mail]);
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
            throw new \LogicException('You must first call $this->parse()');
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
    public function getAllEmailAddresses()
    {
        if (empty($this->mail)) {
            throw new \LogicException('You must first call $this->parse()');
        }

        $addresses = [];
        foreach ($this->mail->getHeader('to')->getAddressList() as $address) {
            $addresses[] = $address->getEmail();
        }

        $addresses = array_unique($addresses);

        return $addresses;
    }
}
