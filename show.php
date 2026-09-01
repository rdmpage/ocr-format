<?php

// examples of dumping info from OCR file

$filename = 'part202055-mistral-common.json';
$filename = 'biostor-192990_djvu-common.json';
$filename = 'part80412-mistral-common.json';
$filename = 'surveyeastpalae426maru_hocr-common.json';

$filename = 'europeanjournal236muse-mistral-common.json';

$json = file_get_contents($filename);

$doc = json_decode($json);

$page = $doc->pages[28];

foreach ($page->blocks as $block)
{
	if ($block->type == "references")
	//if ($block->type == "text")
	//if ($block->type == "word")
	{
		$text = substr($page->text, $block->span[0], $block->span[1] - $block->span[0]);
		
		//echo "|$text|\n\n";
		
		echo "$text\n\n";
	}

}

?>
