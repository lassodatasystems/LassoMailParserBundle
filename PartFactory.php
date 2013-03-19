<?php
namespace Lasso\MailParserBundle;

use Zend\Mail\Storage\Part;

class PartFactory
{
    public function getPart($mailBody)
    {
        return new Part(['raw' => $mailBody]);
    }
}
