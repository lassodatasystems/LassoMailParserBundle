##Lasso Mail Parser [![Build Status](https://travis-ci.org/lassodatasystems/LassoMailParserBundle.png?branch=master)](https://travis-ci.org/lassodatasystems/LassoMailParserBundle)

For when you want easy access to an email's content. The Zend Framework has classes to parse emails,
but they can be a bit clunky to use. For example, emails are split into parts, and you have to loop
over all the parts in an email (which itself is treated as a part) to access the content.

#What it does

The Lasso Mail Parser offers a simple way to get either html or text content from an email, discarding
file attachments. Should both html and text parts be given, html is preferred.

#How it works

An email is parsed with the Zend mail parser. It's parts are then recursively grouped by content type,
such as 'text/plain' or 'text/html'. If any html or text parts are found, they are concatenated and the
result returned. If no such parts are found, null is returned.

The concatenation string is determined by a call to a user-defined function, which makes flexible concatenation
possible.

#Usage

As this is a symfony bundle, you can add this repository to your composer.json file:


https://packagist.org/packages/lasso/mail-parser-bundle


Then request the parser like this:

    $parser = $container->get('lasso_mail_parser.parser');

Of course, it can be used with dependency injection too by adding it to your services.xml as a parameter.

Once you have a parser instance, parse the raw email source like this:

    $parser->parse(YOUR-EMAIL-BODY);

You can then use

    $emailAddresses = $parser->getAllEmailAddresses();

to extract email address from the email. You can restrict the fields used for extraction by passing in an
array with the field names you wish to use:

    // Only retrieve recipients
    $receiverEmailAddresses = $parser->getAllEmailAddress(['to', 'cc', 'bcc']);

Use

    $content = $parser->getPrimaryContent();

to get the main content. This will be html if html is present, else it will be plain text. You can pass in
a custom glue function:

    $glue = function($contentType) {
        switch ($contentType) {
            case 'text/plain':
                return "\n====\n";

            case 'text/html':
                return '<hr />';
        }

        return '';
    }

    $content = $parser->getPrimaryContent($glue);

Now all html parts will be concatenated with hr-tags, and all text parts will be concatenated with
newlines and '===='.

If you are processing emails send via envelope journaling (e.g. from Office365), you can access the enveloped email via

    $parser->getEnvelopedEmail();

This will return a normal part, and you can access content/headers on it. To check if an email has an enveloped
email as an attachment, you can use

    $parser->hasEnvelopedEmail();

