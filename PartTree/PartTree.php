<?php
namespace Lasso\MailParserBundle\PartTree;

use Zend\Mail\Storage\Part;

class PartTree
{
    /** @var PartTreeNode */
    private $root;

    /**
     * @param PartTreeNode $root
     */
    function __construct(PartTreeNode $root)
    {
        $this->root = $root;
    }

    /**
     * @return Part[]
     */
    public function flattenParts()
    {
        return $this->flattenPartsRecurse($this->root);
    }

    /**
     * @return bool
     */
    public function hasEnvelopedEmail()
    {
        return is_null($this->getEnvelopedEmail()) ? false : true;
    }

    /**
     * @return null|Part
     */
    public function getEnvelopedEmail()
    {
        if (!empty($this->root)) {
            foreach ($this->root->getChildren() as $child) {
                if ($child->isEnveloped()) {
                    return $child->getPart();
                }
            }
        }

        return null;
    }

    /**
     * @param PartTreeNode $root
     *
     * @return array
     */
    private function flattenPartsRecurse(PartTreeNode $root)
    {
        $parts = [$root->getPart()];
        foreach ($root->getChildren() as $child) {
            $parts = array_merge($parts, $this->flattenPartsRecurse($child));
        }

        return $parts;
    }
}
