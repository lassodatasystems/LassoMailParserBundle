<?php
namespace Lasso\MailParserBundle;

use Zend\Mail\Storage\Part;

/**
 * Provides a very simple wrapper around the zend mail library. Contains assorted helper functions regarding mail
 * processing.
 */
class LassoMailParserBundle extends Bundle
{

    /**
     * Returns the name of the class.
     *
     * @return string
     */
    public function getName()
    {
        return 'LassoMailParserBundle';
    }
}
