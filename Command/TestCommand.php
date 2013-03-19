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
        $parser->parse('From: test@example.com');
    }
}
