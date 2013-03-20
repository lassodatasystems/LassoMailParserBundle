<?php

namespace Lasso\MailParserBundle\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Process\Process;
use Lasso\DataCollectorBundle\Entity\Client;
use Lasso\DataCollectorBundle\Entity\ClientFtpUser;
use Lasso\DataCollectorBundle\Repository\ClientRepository;
use Doctrine\ORM\EntityManager;
use Exception;

/**
 * Symfony command to list clients
 */
class TestCommand extends ContainerAwareCommand
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this->setName('lasso:parser:test')
            ->setDescription('Test parser instantiation');
    }

    /**
     * {@inheritDoc}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $parser = $this->getContainer()->get('lasso_mail_parser.parser');

        $mail = <<<MAIL
MIME-Version: 1.0
Date: Tue, 19 Mar 2013 11:32:22 -0700
Subject: This is the subject line
From: a@example.com
To: b@example.com
Content-Type: text/plain; charset=ISO-8859-1

This is the text body* with styling*
MAIL;

        $parser->parse($mail);

        $part = $parser->getMail();

        var_dump($parser->getPrimaryContent());
    }
}
