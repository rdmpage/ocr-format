<?php

// Convert Mistral API result

error_reporting(E_ALL);

function type_mapper($type)
{
	switch ($type)
	{
		default:
			break;
	}
	return $type;
}

//----------------------------------------------------------------------------------------
$filename = '';
if ($argc < 2)
{
	echo "Usage: " . basename(__FILE__) . " <filename>\n";
	exit(1);
}
else
{
	$filename = $argv[1];
}

$file_parts = pathinfo($filename);

$output_filename = $file_parts['filename'] . '-common.json';

$json = file_get_contents($filename);
$obj = json_decode($json);

$doc = new stdclass;
$doc->pages = [];
	
foreach ($obj->pages as $p)
{
	$page = new stdclass;
	$page->width  = $p->dimensions->width;
	$page->height = $p->dimensions->height;
	$page->source = $obj->model;
	$page->text = "";
	$page->blocks = [];
	
	// helpers
	$offset = 0;
	$text_blocks = [];
	
	foreach ($p->blocks as $b)
	{			
		$block = new stdclass;
		$block->type = type_mapper($b->type);
		
		$block->bbox =
        [
            round($b->top_left_x      / $page->width, 4),
            round($b->top_left_y      / $page->height, 4),
            round($b->bottom_right_x  / $page->width, 4),
            round($b->bottom_right_y  / $page->height, 4)
        ];		
	
		if (isset($b->content))
		{
			$len = strlen($b->content);
			$block->span = [$offset, $offset + $len];
			$text_blocks[] =  $b->content;			
			$offset += $len + 1;
		}
		
		$page->blocks[] = $block;	
	}	
	
	$page->text = join("\n", $text_blocks);
	
	$doc->pages[] = $page;
}

print_r($doc);

//exit();

file_put_contents($output_filename, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
