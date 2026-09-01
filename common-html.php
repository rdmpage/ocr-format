<?php

// Display one common JSON document as coloured blocks over the page images
//
// Usage: php common-html.php <common.json> [image-dir] > out.html
//

//----------------------------------------------------------------------------------------
// Read the file names, flags (-v) and settings (-offset=1) from the command line
function command_line($argv, $argc, $flags = [])
{
	$options = new stdclass;
	$options->files = [];

	foreach ($flags as $flag)
	{
		$options->$flag = false;
	}

	for ($i = 1; $i < $argc; $i++)
	{
		if (substr($argv[$i], 0, 1) != '-')
		{
			$options->files[] = $argv[$i];
			continue;
		}

		$name = ltrim($argv[$i], '-');

		if (strpos($name, '=') !== false)
		{
			// A setting, e.g. -offset=1
			list($name, $value) = explode('=', $name, 2);
			$options->$name = $value;
		}
		else if (in_array($name, $flags))
		{
			$options->$name = true;
		}
		else
		{
			echo "Unknown option '" . $argv[$i] . "'\n";
			exit(1);
		}
	}

	return $options;
}

$options = command_line($argv, $argc);

if (count($options->files) < 1)
{
	echo "Usage: " . basename(__FILE__) . " <common.json> [image-dir] > out.html\n";
	exit(1);
}

$filename = $options->files[0];

//$image_dir = isset($options->files[1]) ? $options->files[1] : guess_image_dir($filename);

$json = file_get_contents($filename);
$obj = json_decode($json);



	$html = '';
	$html .= '<html>';
	$html .=  '<head>';
	$html .=  '<style>
	body { background-color:rgb(242,242,242)}
div { border:1px solid black; }	
.page { position:relative; margin-bottom:1em; margin-left:auto; margin-right:auto; border:1px solid rgb(192,192,192); max-width:1000px; background:white; }
.page img { display:block; width:100%; border:0; }
.noimage { padding-top:130%; border:0; }

.text { background:green; opacity:0.2; } 
.image { background:red; opacity:0.2; }
.caption { background:yellow; opacity:0.2; }
.table { background:blue; opacity:0.2; }
.header { background:orange; opacity:0.2; }
.footer { background:orange; opacity:0.2; }
.title { background:blue; opacity:0.2; }
.references { background:blue; opacity:0.2; }
.list { background:blue; opacity:0.2; }

/* .separator { background:red; } */

</style>';
$html .=  '</head>';
$html .=  '<body>';


foreach ($obj->pages as $page_number => $page)
{
	$image_filename = '';
	//$image_filename = page_image($image_dir, $page_number);

	$html .= '<div class="page">';

	if ($image_filename != '')
	{
		$html .= '<img src="' . $image_filename . '">';
	}
	else
	{
		// No page image, just draw the blocks on an empty sheet
		$html .= '<div class="noimage"></div>';
	}

	foreach ($page->blocks as $block)
	{
		$html .= '<div class="' . $block->type . '" title="' . $block->type . '" style="position:absolute;'
			. 'left:' . round($block->bbox[0] * 100, 3) . '%;'
			. 'top:' .  round($block->bbox[1] * 100, 3) . '%;'
			. 'width:' . round(($block->bbox[2] - $block->bbox[0]) * 100, 3) . '%;'
			. 'height:' . round(($block->bbox[3] - $block->bbox[1]) * 100, 3) . '%;'
			. '"></div>';

	}

	$html .= '</div>';
}
$html .= '</body>';
$html .= '</html>';

echo $html;

?>
