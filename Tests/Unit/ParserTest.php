<?php
namespace Lasso\MailParserBundle\Tests\Unit;

use Lasso\MailParserBundle\PartFactory;
use Lasso\MailParserBundle\Parser;
use PHPUnit_Framework_TestCase;

require dirname(__FILE__) . '/../../PartFactory.php';
require dirname(__FILE__) . '/../../Parser.php';

class ParserTests extends PHPUnit_Framework_TestCase
{
    protected $partFactory;

    protected $mailBody = <<<MAIL
Date: Mon, 04 Mar 2013 14:30:44 +0100
From: a@example.com
User-Agent: Mozilla/5.0 (X11; U; Linux i686; en-US; rv:1.8.1.23) Gecko/20090817 Thunderbird/2.0.0.23 Mnenhy/0.7.6.666
MIME-Version: 1.0
To: b@example.com
Cc: c@example.com
Bcc: d@example.com
Subject: Testsubject
X-Enigmail-Version: 1.5.1
Content-Transfer-Encoding: 8bit

Testbody
MAIL;

    public function setUp()
    {
        $this->partFactory = $this->getMock('Lasso\MailParserBundle\PartFactory');
    }

    protected function getParser($partFactory = null)
    {
        if (empty($partFactory)) {
            $partFactory = $this->partFactory;
        }

        return new Parser($partFactory);
    }

    /**
     * @test
     */
    public function parsingMailSetsPart()
    {
        $partFactoryMock = $this->getMock(
            'Lasso\MailParserBundle\PartFactory',
            ['getPart']
        );
        $partMock        = $this->getMockBuilder(
            'Zend\Mail\Storage\Part'
        )->disableOriginalConstructor()->getMock();

        $partFactoryMock->expects($this->once())
            ->method('getPart')
            ->will($this->returnValue($partMock));

        $parser = $this->getParser($partFactoryMock);
        $parser->parse('From: someone@example.com');

        $this->assertAttributeEquals($partMock, 'mail', $parser);
    }

    /**
     * @test
     */
    public function getMailReturnsCorrectPartInstance()
    {

        $partFactoryMock = $this->getMock(
            'Lasso\MailParserBundle\PartFactory',
            ['getPart']
        );
        $partMock        = $this->getMockBuilder(
            'Zend\Mail\Storage\Part'
        )->disableOriginalConstructor()->getMock();

        $partFactoryMock->expects($this->once())
            ->method('getPart')
            ->will($this->returnValue($partMock));

        $parser = $this->getParser($partFactoryMock);
        $parser->parse('From: someone@example.com');

        $mail = $parser->getMail();

        $this->assertEquals($partMock, $mail);
    }

    /**
     * @test
     */
    public function extractEmailAddressesFromMail()
    {
        $partFactory = new PartFactory();
        $parser      = $this->getParser($partFactory);
        $parser->parse($this->mailBody);

        $addressesFromEmail = $parser->getAllEmailAddresses();
        $shouldBePresent    = [
            'a@example.com',
            'b@example.com',
            'c@example.com',
            'd@example.com',
        ];
        foreach ($shouldBePresent as $address) {
            $this->assertContains($address, $addressesFromEmail);
        }
    }

    /**
     * @test
     */
    public function extractEmailAddressesFromSpecificHeaders()
    {
        $partFactory = new PartFactory();
        $parser      = $this->getParser($partFactory);
        $parser->parse($this->mailBody);

        $addressesFromEmail = $parser->getAllEmailAddresses(['to', 'bcc']);
        $shouldBePresent    = [
            'b@example.com',
            'd@example.com',
        ];
        foreach ($shouldBePresent as $address) {
            $this->assertContains($address, $addressesFromEmail);
        }
    }
}
