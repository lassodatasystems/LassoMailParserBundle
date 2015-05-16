<?php
namespace Lasso\MailParserBundle;

use ArrayIterator;
use Exception;
use Zend\Mail\Header\HeaderInterface;
use Zend\Mail\Storage\Part;

abstract class ParseHelper {
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
}
