<?php

// Convert hOCR HTML to simple JSON for pages

error_reporting(E_ALL);

ini_set('memory_limit', '-1');

//----------------------------------------------------------------------------------------
function type_mapper($type)
{
	return $type;
}

//----------------------------------------------------------------------------------------
function extract_box($text)
{
	$bbox = array(0,0,0,0);
	
	if (preg_match('/bbox (\d+) (\d+) (\d+) (\d+)/', $text, $m))
	{
		$bbox = array(
			(Integer)$m[1], 
			(Integer)$m[2],
			(Integer)$m[3],
			(Integer)$m[4]
			);
	}

	return $bbox;
}

//----------------------------------------------------------------------------------------
function parse_hocr($filename)
{
	$doc = new stdclass;
	$doc->pages = [];

	$xml = file_get_contents($filename);
				
	$dom = new DOMDocument;
	$dom->loadXML($xml);
	
	$xpath = new DOMXPath($dom);
	$xpath->registerNamespace('xhtml', 'http://www.w3.org/1999/xhtml');	

	foreach($xpath->query ('//xhtml:div[@class="ocr_page"]') as $ocr_page)
	{		
		$page = new stdclass;
		$page->width  = 0;
		$page->height = 0;
		$page->source = 'hocr';
		$page->text = "";
		$page->blocks = [];
		
		// helpers
		$offset = 0;
		$text_blocks = [];
	
		// coordinates and other attributes 
		if ($ocr_page->hasAttributes()) 
		{ 
			$attributes = array();
			$attrs = $ocr_page->attributes; 
		
			foreach ($attrs as $i => $attr)
			{
				$attributes[$attr->name] = $attr->value; 
			}
		}
		
		$bbox = extract_box($attributes['title']);		
		
		$page->width  = $bbox[2];
		$page->height = $bbox[3];
		
		// images (these may be simply page numbers that haven't been recognised)
		foreach($xpath->query ('xhtml:div[@class="ocr_photo"]', $ocr_page) as $ocr_photo)
		{
			$block = new stdclass;
			$block->type = type_mapper('image');
			$block->bbox = [0, 0, 0, 0];
			
			// coordinates
			if ($ocr_photo->hasAttributes()) 
			{ 
				$attributes = array();
				$attrs = $ocr_photo->attributes; 

				foreach ($attrs as $i => $attr)
				{
					$attributes[$attr->name] = $attr->value; 
				}
			}
							
			$bbox = extract_box($attributes['title']);
			$block->bbox =
			[
				round($bbox[0]  / $page->width, 4),
				round($bbox[1]  / $page->height, 4),
				round($bbox[2]  / $page->width, 4),
				round($bbox[3]  / $page->height, 4)
			];	
			
			$page->blocks[] = $block;
		}		
		
		// text
		foreach($xpath->query ('xhtml:div[@class="ocr_carea"]', $ocr_page) as $ocr_carea)
		{
			foreach($xpath->query ('xhtml:p[@class="ocr_par"]', $ocr_carea) as $ocr_par)
			{	
				$block = new stdclass;
				$block->type = type_mapper('text');
				$block->bbox = [0, 0, 0, 0];
				
				$block_offset = $offset;
			
				// coordinates
				if ($ocr_par->hasAttributes()) 
				{ 
					$attributes = array();
					$attrs = $ocr_par->attributes; 

					foreach ($attrs as $i => $attr)
					{
						$attributes[$attr->name] = $attr->value; 
					}
				}
								
				$bbox = extract_box($attributes['title']);
				$block->bbox =
				[
					round($bbox[0]  / $page->width, 4),
					round($bbox[1]  / $page->height, 4),
					round($bbox[2]  / $page->width, 4),
					round($bbox[3]  / $page->height, 4)
				];	
				
				
				foreach($xpath->query ('xhtml:span[@class="ocr_line" or "ocr_caption"]', $ocr_par) as $ocr_line)
				{						
					$line = new stdclass;
					$line->type = type_mapper('line');
					
					// coordinates
					if ($ocr_line->hasAttributes()) 
					{ 
						$attributes = array();
						$attrs = $ocr_line->attributes; 
	
						foreach ($attrs as $i => $attr)
						{
							$attributes[$attr->name] = $attr->value; 
						}
					}						
										
					$bbox = extract_box($attributes['title']);
					$line->bbox =
					[
						round($bbox[0]  / $page->width, 4),
						round($bbox[1]  / $page->height, 4),
						round($bbox[2]  / $page->width, 4),
						round($bbox[3]  / $page->height, 4)
					];	
					
					$line_offset = $offset;
					
					$words_text = array();
												
					foreach($xpath->query ('xhtml:span[@class="ocrx_word"]', $ocr_line) as $ocrx_word)
					{		
						$word = new stdclass;
						$word->type = type_mapper('word');
						
						// coordinates
						if ($ocrx_word->hasAttributes()) 
						{ 
							$attributes = array();
							$attrs = $ocrx_word->attributes; 
		
							foreach ($attrs as $i => $attr)
							{
								$attributes[$attr->name] = $attr->value; 
							}
						}						
											
						$bbox = extract_box($attributes['title']);
						$word->bbox =
						[
							round($bbox[0]  / $page->width, 4),
							round($bbox[1]  / $page->height, 4),
							round($bbox[2]  / $page->width, 4),
							round($bbox[3]  / $page->height, 4)
						];	
											
											
						if (isset($ocrx_word->firstChild->nodeValue))
						{
							$text = $ocrx_word->firstChild->nodeValue;	
						
							$text = mb_convert_encoding($text, "UTF-8", mb_detect_encoding($text));
						
							$len = mb_strlen($text);
							$word->span = [$offset, $offset + $len];
							$offset += $len + 1;
																		
							$words_text[] = $text;
						}	
						
						$page->blocks[] = $word;									
					}	
					
					$text = join(' ', $words_text);
				
					$line->span = [$line_offset, $offset - 1];
										
					$page->blocks[] = $line;

					// add text to page-level text string
					$text_blocks[] = $text;									
				}
				
				$block->span = [$block_offset, $offset - 1];		
								
				$page->blocks[] = $block;
			}
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

$doc = parse_hocr($filename);

print_r($doc);

file_put_contents($output_filename, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); 

?>
