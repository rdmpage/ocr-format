<?php

// examples of dumping info from OCR file

$filename = 'europeanjournal236muse-mistral-common.json';
$filename = 'biostor-192990-mistral-common.json';

$json = file_get_contents($filename);

$doc = json_decode($json);

$page = $doc->pages[0];

foreach ($page->blocks as $block)
{
	//if ($block->type == "references")
	//if ($block->type == "text")
	//if ($block->type == "word")
	if ($block->type == "title")
	//if ($block->type == "table")
	{
		$text = mb_substr($page->text, $block->span[0], $block->span[1] - $block->span[0]);
		
		//echo "|$text|\n\n";
		
		echo "$text\n\n";
	}

}

?>
