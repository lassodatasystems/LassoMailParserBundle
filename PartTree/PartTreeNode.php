<?php
namespace Lasso\MailParserBundle\PartTree;

use Zend\Mail\Storage\Part;

class PartTreeNode
{
    /** @var Part */
    private $part;
    /** @var boolean */
    private $isEnveloped;
    /** @var PartTreeNode[] */
    private $children = [];

    function __construct(
        Part $part,
        $isEnveloped
    )
    {
        $this->isEnveloped = $isEnveloped;
        $this->part        = $part;
    }

    public function addChild(PartTreeNode $partTreeNode)
    {
        $this->children[] = $partTreeNode;
    }

    /**
     * @return PartTreeNode[]
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @return boolean
     */
    public function isEnveloped()
    {
        return $this->isEnveloped;
    }

    /**
     * @return Part
     */
    public function getPart()
    {
        return $this->part;
    }
}
