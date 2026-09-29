<?php

require_once(dirname(__FILE__) . '/string-map.php');

//----------------------------------------------------------------------------------------
// Given a list of strings (taxon names, place names, ...) and the pages they occur on,
// find each string on its page and emit IIIF/W3C annotations with xywh targets, a TSV
// summary of the matches, or an HTML preview.
//
// Usage: php strings.php [-tsv|-html] [-canvas=<url template>] [-image=<url template>]
//                        [-context=<n>] <common.json> <strings.tsv> > out
//
// The input TSV has one string per line:
// page<TAB>string[<TAB>source[<TAB>locator[<TAB>notes]]]
//
// - page is the index into "pages" in the common JSON (0-based)
// - source is where we were told the string is on this page, typically a dataset DOI
//   or other URI
// - locator identifies the entry in that source, e.g. the URL or LSID of a taxonomic
//   name, or a row fragment identifier (#row=n) for a CSV file
// - notes is anything the source says about this bit of text
//
// source, locator and notes are copied through to the output unchanged. If a row has no source,
// the input file itself is the source (its file name) and the row's line number in that
// file is the locator (#row=n, counting a header line, as RFC 7111 does for CSV). Lines
// starting with # and a header line (non-numeric page) are skipped.
//
// Output (JSON by default):
//
// -tsv    one row per match, for loading into a database. The first column is the file
//         name of the common JSON, to say which volume the rows belong to. A string that wasn't found gets
//         a row with the match columns empty, so every input row is accounted for. See
//         tsv_escape() for how tabs and line breaks in the text are written. There is no
//         canvas, that's decided when the rows are loaded, but the page's width and height
//         are included so they can be checked against the image the canvas is built from.
// -html   draws the matches over each page
//
// Options:
//
// -canvas   the IIIF canvas for a page in the JSON output, e.g.
//           -canvas='https://example.org/canvas/p{n}'
// -image    page image for -html, e.g. for the Internet Archive use its IIIF image
//           server, whose page numbers match the hOCR's (its /page/n{N} URLs can be out
//           by one):
//           -image='https://iiif.archive.org/iiif/lepidopteraofcey01moor${page}/full/1000,/0/default.jpg'
// -context  characters of prefix and suffix to record (default 32)
//
// In the templates {page} is replaced by the 0-based page index and {n} by the 1-based
// page number.
//

// Common JSON for a whole volume can be large
ini_set('memory_limit', '-1');

//----------------------------------------------------------------------------------------
// Make text safe for a TSV cell. We use the escapes that PostgreSQL's COPY and MySQL's
// LOAD DATA understand by default: \t, \n, \r and \\.
function tsv_escape($text)
{
	return str_replace(["\\", "\t", "\n", "\r"], ["\\\\", "\\t", "\\n", "\\r"], (string)$text);
}

//----------------------------------------------------------------------------------------
function page_template($template, $page_number)
{
	return str_replace(['{page}', '{n}'], [$page_number, $page_number + 1], $template);
}

$format = 'json';
$canvas_template = null;
$image_template = null;
$context = 32;
$files = [];

foreach (array_slice($argv, 1) as $argument)
{
	if ($argument == '-html' || $argument == '-tsv')
	{
		$format = substr($argument, 1);
	}
	elseif (preg_match('/^-(canvas|image|context)=(.*)$/', $argument, $m))
	{
		switch ($m[1])
		{
			case 'canvas':
				$canvas_template = $m[2];
				break;

			case 'image':
				$image_template = $m[2];
				break;

			case 'context':
				$context = (int)$m[2];
				break;
		}
	}
	else
	{
		$files[] = $argument;
	}
}

if (count($files) < 2)
{
	fwrite(STDERR, "Usage: php strings.php [-tsv|-html] [-canvas=<url template>] [-image=<url template>] [-context=<n>] <common.json> <strings.tsv>\n");
	exit(1);
}

list($filename, $strings_filename) = $files;

foreach ([$filename, $strings_filename] as $f)
{
	if (!file_exists($f))
	{
		fwrite(STDERR, "Can't read '" . $f . "'\n");
		exit(1);
	}
}

$base = 'https://example.org/iiif/' . basename($filename, '.json');

if (!$canvas_template)
{
	$canvas_template = $base . '/canvas/p{n}';
}

// Group the input rows by page. The same string may be listed more than once for a
// page (e.g. by different sources), so we keep every row, and match each string once.
$pages = [];

foreach (file($strings_filename, FILE_IGNORE_NEW_LINES) as $line_index => $line)
{
	if (trim($line) == '' || substr($line, 0, 1) == '#')
	{
		continue;
	}

	$row = explode("\t", $line);

	if (count($row) < 2 || !is_numeric($row[0]))
	{
		continue;
	}

	$input = new stdclass;
	$input->page = (int)$row[0];
	$input->value = trim($row[1]);
	$input->source = isset($row[2]) ? trim($row[2]) : '';
	$input->locator = isset($row[3]) ? trim($row[3]) : '';
	$input->notes = isset($row[4]) ? trim($row[4]) : '';

	// No source given, so the source is this file and the locator is this row
	if ($input->source == '')
	{
		$input->source = basename($strings_filename);
		$input->locator = '#row=' . ($line_index + 1);
	}

	$pages[$input->page][] = $input;
}

ksort($pages);

$obj = json_decode(file_get_contents($filename));

$output = [];
$previews = [];

if ($format == 'tsv')
{
	echo implode("\t", ['volume', 'page', 'string', 'source', 'locator', 'width', 'height', 'text', 'notes', 'start', 'end',
		'prefix', 'suffix', 'xywh', 'distance']) . "\n";
}

foreach ($pages as $page_number => $inputs)
{
	if (!isset($obj->pages[$page_number]))
	{
		fwrite(STDERR, "No page " . $page_number . "\n");
		continue;
	}

	$page = $obj->pages[$page_number];
	$canvas = page_template($canvas_template, $page_number);

	$values = array_map(function($input) { return $input->value; }, $inputs);

	$matches = find_strings($page, $values);

	$found = [];
	$rows = [];

	foreach ($matches as $index => $match)
	{
		$found[$match->value][] = $match;

		$match->regions = span_to_regions($page, $match->start, $match->end);

		$match->prefix = mb_substr($page->text, max(0, $match->start - $context), min($match->start, $context));
		$match->suffix = mb_substr($page->text, $match->end, $context);

		// JSON annotation
		$annotation = new stdclass;

		$body = new stdclass;
		$body->type = 'TextualBody';
		$body->purpose = 'identifying';
		$body->value = $match->value;

		$annotation->body = $body;

		// If the page text doesn't say exactly this, record what it does say
		if ($match->distance > 0)
		{
			$ocr = new stdclass;
			$ocr->type = 'TextualBody';
			$ocr->purpose = 'describing';
			$ocr->value = 'Page text: ' . $match->text;

			$annotation->body = [$body, $ocr];
		}

		$id = $base . '/annotation/p' . ($page_number + 1) . '-' . $index;

		foreach (iiif_annotation($annotation, $canvas, $match->regions, $id) as $iiif)
		{
			$output[] = $iiif;
		}

		// HTML preview
		$row = new stdclass;
		$row->value = $match->value;
		$row->text = $match->text;
		$row->distance = $match->distance;
		$row->regions = $match->regions;

		$rows[] = $row;

		fwrite(STDERR, $page_number . "\t" . $match->value . "\t[" . tsv_escape($match->text) . "]\t"
			. $match->distance . "\t" . implode(' ', array_map(function($r) { return $r->xywh; }, $match->regions)) . "\n");
	}

	// TSV, one row per input row per match, in input order
	$missing = [];

	foreach ($inputs as $input)
	{
		if (!isset($found[$input->value]))
		{
			$missing[$input->value] = true;
		}

		if ($format != 'tsv')
		{
			continue;
		}

		$input_columns = [basename($filename), $input->page, $input->value, $input->source, $input->locator, (int)$page->width, (int)$page->height];

		if (!isset($found[$input->value]))
		{
			echo implode("\t", array_map('tsv_escape', array_merge($input_columns, ['', $input->notes, '', '', '', '', '', '']))) . "\n";
			continue;
		}

		foreach ($found[$input->value] as $match)
		{
			// A JSON array of [x, y, w, h], usually just one, but a match that runs over a
			// line break has one rectangle per line
			$xywh = json_encode(array_map(function($region)
			{
				return array_map('intval', explode(',', $region->xywh));
			}, $match->regions));

			echo implode("\t", array_map('tsv_escape', array_merge($input_columns, [
				$match->text,
				$input->notes,
				$match->start,
				$match->end,
				$match->prefix,
				$match->suffix,
				$xywh,
				$match->distance
			])))  . "\n";
		}
	}

	foreach (array_keys($missing) as $value)
	{
		fwrite(STDERR, $page_number . "\t" . $value . "\tNOT FOUND\n");
	}

	if ($format == 'html')
	{
		$image = $image_template ? page_template($image_template, $page_number) : null;

		$heading = 'Page ' . $page_number
			. (count($missing) > 0 ? ' (not found: ' . implode(', ', array_keys($missing)) . ')' : '');

		$previews[] = annotation_preview_page($page, $rows, $image, $heading);
	}
}

switch ($format)
{
	case 'html':
		echo annotation_preview_html($previews);
		break;

	case 'json':
		$annotation_page = new stdclass;
		$annotation_page->{'@context'} = 'http://iiif.io/api/presentation/3/context.json';
		$annotation_page->id = $base . '/annotations/strings';
		$annotation_page->type = 'AnnotationPage';
		$annotation_page->items = $output;

		echo json_encode($annotation_page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
		break;

	default:
		break;
}

?>
