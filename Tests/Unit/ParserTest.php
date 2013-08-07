<?php
/*
 * Copyright 2013 Lasso Data Systems
 *
 * This file is part of LassoMailParser.
 *
 * LassoMailParser is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * LassoMailParser is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with LassoMailParser. If not, see <http://www.gnu.org/licenses/>.
 *
 */
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

    /**
     * The html content is preferred with text body as a fallback.
     * The file should always be ignored.
     *
     * @test
     */
    public function extractHtmlContentFromMultiPartEmail()
    {
        $mailBody = <<<MAIL
MIME-Version: 1.0
Date: Tue, 19 Mar 2013 11:32:22 -0700
Subject: This is the subject line
From: a@example.com
To: b@example.com
Content-Type: multipart/mixed; boundary=047d7bb03b8e84404004d84b538e

--047d7bb03b8e84404004d84b538e
Content-Type: multipart/alternative; boundary=047d7bb03b8e84403b04d84b538c

--047d7bb03b8e84403b04d84b538c
Content-Type: text/plain; charset=ISO-8859-1

This is the text body* with styling*

--047d7bb03b8e84403b04d84b538c
Content-Type: text/html; charset=ISO-8859-1
Content-Transfer-Encoding: quoted-printable

<div>This is the html body<b>with styling</b></div>

--047d7bb03b8e84403b04d84b538c--
--047d7bb03b8e84404004d84b538e
Content-Type: application/x-font-ttf; name="example.bin"
Content-Disposition: attachment; filename="example.bin"
Content-Transfer-Encoding: base64
X-Attachment-Id: f_hehegkyx0

AAEAAAAPADAAAwDAT1MvMlFBXLsAAYF0AAAAVlBDTFRLxMEKAAGBzAAAADZjbWFwXFxQcgABdkQA
--047d7bb03b8e84404004d84b538e--
MAIL;
        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);

        $content = $parser->getPrimaryContent();
        $this->assertEquals('<div>This is the html body<b>with styling</b></div>', $content);
    }

    /**
     * @test
     */
    public function primaryBodyFromMultiPartEmail()
    {
        $mailBody = <<<MAIL
MIME-Version: 1.0
Date: Tue, 19 Mar 2013 11:32:22 -0700
Subject: This is the subject line
From: a@example.com
To: b@example.com
Content-Type: multipart/mixed; boundary=047d7bb03b8e84404004d84b538e

--047d7bb03b8e84404004d84b538e
Content-Type: multipart/alternative; boundary=047d7bb03b8e84403b04d84b538c

--047d7bb03b8e84403b04d84b538c
Content-Type: text/plain; charset=ISO-8859-1

This is the text body* with styling*

--047d7bb03b8e84403b04d84b538c--
--047d7bb03b8e84404004d84b538e
Content-Type: application/x-font-ttf; name="example.bin"
Content-Disposition: attachment; filename="example.bin"
Content-Transfer-Encoding: base64
X-Attachment-Id: f_hehegkyx0

AAEAAAAPADAAAwDAT1MvMlFBXLsAAYF0AAAAVlBDTFRLxMEKAAGBzAAAADZjbWFwXFxQcgABdkQA
--047d7bb03b8e84404004d84b538e--
MAIL;
        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);

        $content = $parser->getPrimaryContent();
        $this->assertEquals('This is the text body* with styling*', $content);
    }

    /**
     * @test
     */
    public function fileAttachmentsAreIgnoredOnMailsWithNoTextOrHtmlBody()
    {
        $mailBody = <<<MAIL
MIME-Version: 1.0
Date: Tue, 19 Mar 2013 11:32:22 -0700
Subject: This is the subject line
From: a@example.com
To: b@example.com
Content-Type: application/x-font-ttf; name="example.bin"
Content-Disposition: attachment; filename="example.bin"
Content-Transfer-Encoding: base64
X-Attachment-Id: f_hehegkyx0

AAEAAAAPADAAAwDAT1MvMlFBXLsAAYF0AAAAVlBDTFRLxMEKAAGBzAAAADZjbWFwXFxQcgABdkQA
MAIL;
        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);

        $content = $parser->getPrimaryContent();
        $this->assertEquals(null, $content);
    }

    protected function makeGlue()
    {
        return function($contentType) {
            if ($contentType == 'text/html') {
                return '<hr />';
            } else {
                return "\n=====\n";
            }
        };
    }

    /**
     * @test
     */
    public function combineMultipartHtmlIntoContent()
    {
        $parts = [
            '<div id="first">first-part</div>',
            '<div id="second">second-part</div>'
        ];

        $mailBody = <<<MAIL
From: bjames.mylasso@gmail.com
Content-Type: multipart/alternative; boundary="test-boundary"
Subject: Mail list Page
Message-Id: <74111298-6423-2943-9875-39906A7EA733@example.com>
Date: Thu, 2 May 2013 12:31:20 +0200
To: cory.cutterson@test.com, jane.crazy@example.com
Mime-Version: 1.0 (Mac OS X Mail 6.3 \(1503\))
X-Mailer: Apple Mail (2.1503)


--test-boundary
Content-Transfer-Encoding: 7bit
Content-Type: text/html

{$parts[0]}

--test-boundary
Content-Transfer-Encoding: 7bit
Content-Type: text/html

{$parts[1]}

--test-boundary--

MAIL;
        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);
        $content = $parser->getPrimaryContent($this->makeGlue());

        $this->assertEquals($parts[0] . '<hr />' . $parts[1], $content);
    }

    /**
     * @test
     */
    public function combineMultipartTextIntoContent()
    {
        $parts = [
            'first-part',
            'second-part'
        ];

        $mailBody = <<<MAIL
From: bjames.mylasso@gmail.com
Content-Type: multipart/alternative; boundary="test-boundary"
Subject: Mail list Page
Message-Id: <74111298-6423-2943-9875-39906A7EA733@example.com>
Date: Thu, 2 May 2013 12:31:20 +0200
To: cory.cutterson@test.com, jane.crazy@example.com
Mime-Version: 1.0 (Mac OS X Mail 6.3 \(1503\))
X-Mailer: Apple Mail (2.1503)


--test-boundary
Content-Transfer-Encoding: 7bit
Content-Type: text/plain

{$parts[0]}

--test-boundary
Content-Transfer-Encoding: 7bit
Content-Type: text/plain

{$parts[1]}

--test-boundary--

MAIL;
        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);
        $content = $parser->getPrimaryContent($this->makeGlue());

        $this->assertEquals($parts[0] . "\n=====\n" . $parts[1], $content);
    }

    /**
     * @test
     */
    public function extractPlainTextFromNonMultiPartMessage()
    {
        $mailBody = <<<MAIL
MIME-Version: 1.0
Date: Tue, 19 Mar 2013 11:32:22 -0700
Subject: This is the subject line
From: a@example.com
To: b@example.com
Content-Type: text/plain; charset=ISO-8859-1

This is the text body* with styling*
MAIL;
        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);

        $content = $parser->getPrimaryContent();
        $this->assertEquals('This is the text body* with styling*', $content);
    }

    /**
     * @test
     */
    public function decodeQuotedPrintableCorrectly()
    {
        $mailBody = file_get_contents(__DIR__ . '/example_emails/quoted_printable_html.txt');

        $parser = $this->getParser(new PartFactory());
        $parser->parse($mailBody);

        $content = $parser->getPrimaryContent();

        $this->assertEquals('<html><head><meta http-equiv="Content-Type" content="text/html charset=us-ascii"></head><body style="word-wrap: break-word; -webkit-nbsp-mode: space; -webkit-line-break: after-white-space; ">simple html test</body></html>', $content);
    }
}
