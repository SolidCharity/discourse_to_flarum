<?php

include __DIR__ . '/vendor/autoload.php';
use s9e\TextFormatter\Bundles\Forum as TextFormatter;

// init TextFormatter
global $parser;
$configurator = new s9e\TextFormatter\Configurator;
$configurator->plugins->load('Litedown');
$configurator->plugins->load('BBCodes');

$configurator->BBCodes->addFromRepository('CODE');

// see https://github.com/flarum/core/blob/master/src/Formatter/Formatter.php
$configurator->rootRules->enableAutoLineBreaks();
$configurator->Escaper;
$configurator->Autoemail;
$configurator->Autolink;
$configurator->tags->onDuplicate('replace');

extract($configurator->finalize());

$text = "test [mein link](http://www.google.com) test";
$text = "test http://www.google.com test";

$text = $parser->parse($text);
echo $text;
echo "\n";

$text = "test [code]test[/code]";
$text = $parser->parse($text);
echo $text;
echo "\n";


?>
