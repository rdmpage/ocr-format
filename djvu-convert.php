<?php

// Convert DjVu XML to simple JSON for pages

error_reporting(E_ALL);

ini_set('memory_limit', '-1');

//----------------------------------------------------------------------------------------
function type_mapper($type)
{
	return $type;
}

//----------------------------------------------------------------------------------------
function parse_djvu($filename)
{
	$doc = new stdclass;
	$doc->pages = [];

	$xml = file_get_contents($filename);
				
	$dom = new DOMDocument;
	$dom->loadXML($xml);
	
	$xpath = new DOMXPath($dom);

	foreach($xpath->query ('//OBJECT') as $object)
	{		
		$page = new stdclass;
		$page->width  = 0;
		$page->height = 0;
		$page->source = 'djvu';
		$page->text = "";
		$page->blocks = [];
		
		// helpers
		$offset = 0;
		$text_blocks = [];
	
		// coordinates and other attributes 
		if ($object->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $object->attributes; 
		
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
		}
		
		$page->width  = $attributes['width'];
		$page->height = $attributes['height'];
		
		foreach($xpath->query ('HIDDENTEXT/PAGECOLUMN/REGION/PARAGRAPH', $object) as $p)
		{
			$block = new stdclass;
			$block->type = type_mapper('text');
			$block->bbox = [1, 1, 0, 0];
			
			$block_offset = $offset;
			
			foreach ($xpath->query ('LINE', $p) as $l)
			{
				$line = new stdclass;
				$line->type = type_mapper('line');
				$line->bbox = [1, 1, 0, 0];
				
				$line_offset = $offset;
				
				$words_text = array();
						
				$words = $xpath->query ('WORD', $l);
				foreach($xpath->query ('WORD', $l) as $w)
				{											
					// coordinates and other attributes 
					if ($w->hasAttributes()) 
					{ 
						$attributes = array();
						$attrs = $w->attributes; 
					
						foreach ($attrs as $i => $attr)
						{
							$attributes[$attr->name] = $attr->value; 
						}
					}
					
					$word = new stdclass;
					$word->type = type_mapper('word');
										
					$bbox = explode(",", $attributes['coords']);
					
					$word->bbox =
					[
						round($bbox[0]  / $page->width, 4),
						round($bbox[3]  / $page->height, 4),
						round($bbox[2]  / $page->width, 4),
						round($bbox[1]  / $page->height, 4)
					];	
					
					$line->bbox =
					[
						min($line->bbox[0], $word->bbox[0]),
						min($line->bbox[1], $word->bbox[1]),
						max($line->bbox[2], $word->bbox[2]),
						max($line->bbox[3], $word->bbox[3])
					];		
					
					if (isset($w->firstChild->nodeValue))
					{
						$text = $w->firstChild->nodeValue;	
						
						$text = mb_convert_encoding($text, "UTF-8", mb_detect_encoding($text));
						
						$len = mb_strlen($text);
						$word->span = [$offset, $offset + $len];
						$offset += $len + 1;
																	
						$words_text[] = $text;
					}	
					
					$page->blocks[] = $word;									
				}	
				
				$block->bbox =
				[
					min($line->bbox[0], $block->bbox[0]),
					min($line->bbox[1], $block->bbox[1]),
					max($line->bbox[2], $block->bbox[2]),
					max($line->bbox[3], $block->bbox[3])
				];				
				
				$text = join(' ', $words_text);
				
				$line->span = [$line_offset, $offset - 1];
						
				// add line to list of blocks
				$page->blocks[] = $line;
				
				// add text to page-level text string
				$text_blocks[] = $text;
			}	
			
			$block->span = [$block_offset, $offset - 1];		
			
			$page->blocks[] = $block;
		}	
		$page->text = join("\n", $text_blocks);
		
		$doc->pages[] = $page;
	}	
	return $doc;
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

$doc = parse_djvu($filename);

print_r($doc);

file_put_contents($output_filename, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); 

?>
