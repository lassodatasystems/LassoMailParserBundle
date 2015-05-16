<?php
namespace Lasso\MailParserBundle\PartTree;

use Exception;
use Lasso\MailParserBundle\ParseHelper;
use Lasso\MailParserBundle\PartFactory;
use Zend\Mail\Storage\Part;

class PartTreeFactory extends ParseHelper
{
    /**
     * @var PartFactory
     */
    private $partFactory;

    /**
     * @param PartFactory $partFactory
     */
    function __construct(PartFactory $partFactory)
    {
        $this->partFactory = $partFactory;
    }

    public function make(Part $root)
    {
        return new PartTree(
            $this->buildTreeNode(
                new PartTreeNode(
                    $root,
                    $this->isEnvelopedEmail($root)
                )
            )
        );
    }

    /**
     * @param PartTreeNode $root
     *
     * @return PartTreeNode
     */
    private function buildTreeNode(PartTreeNode $root)
    {
        /*
         * $part->countParts(); can throw an error if the headers are missing.
         * Return an empty array if the headers are indeed missing.
         */
        if (count($root->getPart()->getHeaders()) === 0) {
            return $root;
        }

        try {
            $partCount = $root->getPart()->countParts();
        } catch (Exception $e) {
            return $root;
        }

        for ($i = 1; $i <= $partCount; ++$i) {
            $newPart = $root->getPart()->getPart($i);

            if (count($newPart->getHeaders()) === 0) {
                continue;
            }

            if ($newPart->isMultipart()) {
                $root->addChild($this->buildTreeNode(
                    new PartTreeNode(
                        $newPart,
                        false
                    )
                ));
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

                $root->addChild($this->buildTreeNode(
                    new PartTreeNode(
                        $newPart,
                        true
                    )
                ));
            } else {
                $root->addChild(
                    new PartTreeNode(
                        $newPart,
                        false
                    )
                );
            }
        }
        return $root;
    }
}
