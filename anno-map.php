<?php

//----------------------------------------------------------------------------------------
// Map text annotations (W3C Web Annotation with TextQuote/TextPosition selectors) onto
// the word coordinates in a "common" OCR JSON page, and emit IIIF/W3C annotations that
// use media fragment (xywh) selectors.
//
// The hard parts, and how they are handled:
//
// 1. Offsets drift, and they aren't even in the same units. The common JSON counts spans
//    in *characters* (the converters use mb_strlen), but PHP tools such as taxonfinder-php
//    count in *bytes*, so on any page with a non-ASCII character the two disagree. And if
//    the common JSON is rebuilt, or comes from a different OCR engine, the offsets move
//    anyway. So we re-anchor using the TextQuoteSelector (prefix/exact/suffix) and treat
//    the TextPositionSelector as a hint only, trying it as both a character and a byte
//    offset. Everything we return is a character offset, to match the blocks.
//
// 2. Annotations span line breaks. A name such as "Proctoporus machupicchu" can be split
//    over two lines (or even two columns). One bounding box would cover unrelated text, so
//    we emit one rectangle per line: words are grouped by the line block that contains
//    them, and each group's boxes are unioned into a single xywh.
//
//----------------------------------------------------------------------------------------

//----------------------------------------------------------------------------------------
// Collapse runs of whitespace so that context matching survives re-flowed line breaks.
function normalise_text($text)
{
	return preg_replace('/\s+/u', ' ', $text);
}

//----------------------------------------------------------------------------------------
// Length of the longest common suffix of two strings (byte-wise, good enough for scoring)
function common_suffix_length($a, $b)
{
	$n = min(strlen($a), strlen($b));
	$i = 0;
	while ($i < $n && $a[strlen($a) - 1 - $i] === $b[strlen($b) - 1 - $i])
	{
		$i++;
	}
	return $i;
}

//----------------------------------------------------------------------------------------
// Length of the longest common prefix of two strings
function common_prefix_length($a, $b)
{
	$n = min(strlen($a), strlen($b));
	$i = 0;
	while ($i < $n && $a[$i] === $b[$i])
	{
		$i++;
	}
	return $i;
}

//----------------------------------------------------------------------------------------
// Pull the selectors out of an annotation target (which may be a single object or a list)
function annotation_selectors($annotation)
{
	$selectors = [];

	if (!isset($annotation->target))
	{
		return $selectors;
	}

	$targets = is_array($annotation->target) ? $annotation->target : [$annotation->target];

	foreach ($targets as $target)
	{
		if (!isset($target->selector))
		{
			continue;
		}

		$list = is_array($target->selector) ? $target->selector : [$target->selector];

		foreach ($list as $selector)
		{
			$selectors[] = $selector;
		}
	}

	return $selectors;
}

//----------------------------------------------------------------------------------------
// Find where an annotation actually sits in $text.
//
// Returns an object with ->start, ->end, ->exact, ->method, ->score, or false if we can't
// place it. The TextQuoteSelector wins, the TextPositionSelector is only a tie-breaker.
function anchor_annotation($text, $annotation, $context_window = 32)
{
	$quote = null;
	$position = null;

	foreach (annotation_selectors($annotation) as $selector)
	{
		if (!isset($selector->type))
		{
			continue;
		}

		switch ($selector->type)
		{
			case 'TextQuoteSelector':
				$quote = $selector;
				break;

			case 'TextPositionSelector':
				$position = $selector;
				break;

			default:
				break;
		}
	}

	// No quote to match on, so we have to trust the offsets
	if (!$quote || !isset($quote->exact) || $quote->exact === '')
	{
		if ($position && isset($position->start) && isset($position->end))
		{
			$result = new stdclass;
			$result->start = (int)$position->start;
			$result->end = (int)$position->end;
			$result->exact = mb_substr($text, $result->start, $result->end - $result->start);
			$result->method = 'position';
			$result->score = 0;

			return $result;
		}

		return false;
	}

	$exact = $quote->exact;
	$length = mb_strlen($exact);

	// The recorded position may be a character offset or a byte offset, so consider both
	$hints = [];

	if ($position && isset($position->start))
	{
		$hints[] = (int)$position->start;

		if ((int)$position->start <= strlen($text))
		{
			$hints[] = mb_strlen(substr($text, 0, (int)$position->start));
		}
	}

	// If one of the hints lines up exactly we are done
	foreach ($hints as $hint)
	{
		if (mb_substr($text, $hint, $length) === $exact)
		{
			$result = new stdclass;
			$result->start = $hint;
			$result->end = $hint + $length;
			$result->exact = $exact;
			$result->method = 'position+quote';
			$result->score = PHP_INT_MAX;

			return $result;
		}
	}

	// Otherwise score every occurrence of the exact text by how well its surroundings
	// match the recorded prefix and suffix
	$prefix = normalise_text(isset($quote->prefix) ? $quote->prefix : '');
	$suffix = normalise_text(isset($quote->suffix) ? $quote->suffix : '');

	$best = false;
	$offset = 0;

	while (($pos = mb_strpos($text, $exact, $offset)) !== false)
	{
		$before = normalise_text(mb_substr($text, max(0, $pos - $context_window * 2), min($pos, $context_window * 2)));
		$after  = normalise_text(mb_substr($text, $pos + $length, $context_window * 2));

		$score = common_suffix_length($prefix, $before) + common_prefix_length($suffix, $after);

		// Prefer the candidate nearest the recorded position when scores tie
		$distance = PHP_INT_MAX;

		foreach ($hints as $hint)
		{
			$distance = min($distance, abs($pos - $hint));
		}

		if (count($hints) == 0)
		{
			$distance = 0;
		}

		if ($best === false || $score > $best->score || ($score == $best->score && $distance < $best->distance))
		{
			$best = new stdclass;
			$best->start = $pos;
			$best->end = $pos + $length;
			$best->exact = $exact;
			$best->method = 'quote';
			$best->score = $score;
			$best->distance = $distance;
		}

		$offset = $pos + 1;
	}

	return $best;
}

//----------------------------------------------------------------------------------------
// Does a block have text, and does its span overlap [start, end)?
//
// Spans are half open, so a block that starts exactly where the annotation ends (the usual
// case for the next word after a trailing newline) is not included.
function block_overlaps_span($block, $start, $end)
{
	if (!isset($block->span))
	{
		return false;
	}

	return $block->span[0] < $end && $block->span[1] > $start;
}

//----------------------------------------------------------------------------------------
// The finest grained block type this page actually has.
//
// DjVu and hOCR give us words; Mistral only gives us paragraph level blocks, in which case
// the best we can do on that page is highlight the whole block. Returns false to mean
// "no word or line blocks, use every block that has a span".
function finest_block_type($page)
{
	$types = [];

	foreach ($page->blocks as $block)
	{
		if (isset($block->span))
		{
			$types[$block->type] = true;
		}
	}

	foreach (['word', 'line'] as $type)
	{
		if (isset($types[$type]))
		{
			return $type;
		}
	}

	return false;
}

//----------------------------------------------------------------------------------------
// Union of a list of [x0, y0, x1, y1] boxes
function bbox_union($boxes)
{
	$union = null;

	foreach ($boxes as $box)
	{
		if ($union === null)
		{
			$union = $box;
			continue;
		}

		$union[0] = min($union[0], $box[0]);
		$union[1] = min($union[1], $box[1]);
		$union[2] = max($union[2], $box[2]);
		$union[3] = max($union[3], $box[3]);
	}

	return $union;
}

//----------------------------------------------------------------------------------------
// Normalised bbox to a IIIF/media fragment xywh in image pixels
function bbox_to_xywh($bbox, $width, $height, $padding = 0.0)
{
	$x0 = max(0.0, $bbox[0] - $padding);
	$y0 = max(0.0, $bbox[1] - $padding);
	$x1 = min(1.0, $bbox[2] + $padding);
	$y1 = min(1.0, $bbox[3] + $padding);

	$x = (int)round($x0 * $width);
	$y = (int)round($y0 * $height);
	$w = (int)round(($x1 - $x0) * $width);
	$h = (int)round(($y1 - $y0) * $height);

	return $x . ',' . $y . ',' . max(1, $w) . ',' . max(1, $h);
}

//----------------------------------------------------------------------------------------
// Group the blocks covering a span into runs that share a line.
//
// If the page has "line" blocks we use those (a word belongs to the line whose span
// contains it). If it doesn't, we fall back to clustering on the vertical mid point, which
// also copes with the text jumping between columns.
function group_blocks_by_line($page, $blocks)
{
	$lines = [];

	foreach ($page->blocks as $block)
	{
		if ($block->type == 'line' && isset($block->span))
		{
			$lines[] = $block;
		}
	}

	$groups = [];

	if (count($lines) > 0)
	{
		foreach ($blocks as $block)
		{
			$key = 'orphan-' . $block->span[0];

			foreach ($lines as $index => $line)
			{
				if ($block->span[0] >= $line->span[0] && $block->span[1] <= $line->span[1])
				{
					$key = 'line-' . $index;
					break;
				}
			}

			$groups[$key][] = $block;
		}

		return array_values($groups);
	}

	// No line blocks, cluster on vertical position
	$current = [];
	$current_mid = null;

	foreach ($blocks as $block)
	{
		$mid = ($block->bbox[1] + $block->bbox[3]) / 2;
		$tolerance = ($block->bbox[3] - $block->bbox[1]) / 2;

		if ($current_mid !== null && abs($mid - $current_mid) > $tolerance)
		{
			$groups[] = $current;
			$current = [];
		}

		$current[] = $block;
		$current_mid = $mid;
	}

	if (count($current) > 0)
	{
		$groups[] = $current;
	}

	return $groups;
}

//----------------------------------------------------------------------------------------
// Turn a character span on a page into one rectangle per line of text.
//
// Returns a list of objects with ->xywh, ->bbox, ->span and ->text.
function span_to_regions($page, $start, $end, $padding = 0.0)
{
	// Word blocks if we have them, else line blocks, else whatever blocks the page does
	// have (Mistral only gives us paragraph level blocks, so highlights are that coarse)
	$type = finest_block_type($page);

	$matched = [];

	foreach ($page->blocks as $block)
	{
		if ($type && $block->type != $type)
		{
			continue;
		}

		if (block_overlaps_span($block, $start, $end))
		{
			$matched[] = $block;
		}
	}

	usort($matched, function($a, $b) { return $a->span[0] - $b->span[0]; });

	$regions = [];

	foreach (group_blocks_by_line($page, $matched) as $group)
	{
		$boxes = [];
		$span = [PHP_INT_MAX, 0];

		foreach ($group as $block)
		{
			$boxes[] = $block->bbox;
			$span[0] = min($span[0], $block->span[0]);
			$span[1] = max($span[1], $block->span[1]);
		}

		$bbox = bbox_union($boxes);

		$region = new stdclass;
		$region->bbox = $bbox;
		$region->span = $span;
		$region->text = mb_substr($page->text, $span[0], $span[1] - $span[0]);
		$region->xywh = bbox_to_xywh($bbox, (int)$page->width, (int)$page->height, $padding);

		$regions[] = $region;
	}

	return $regions;
}

//----------------------------------------------------------------------------------------
// Build IIIF annotations for the rectangles of this annotation, for adding to a manifest.
//
// Each target is the canvas URI with an xywh fragment ("canvas#xywh=x,y,w,h"), not a
// SpecificResource with a FragmentSelector. Both are valid, but viewers such as Tify only
// understand the plain URI. For the same reason a span that covers several lines gives
// one annotation per rectangle (with /0, /1, ... appended to the id), because Tify
// ignores an array of targets. Set $split to false to get a single annotation with an
// array of targets instead.
function iiif_annotation($annotation, $canvas, $regions, $id = null, $split = true)
{
	$targets = [];

	foreach ($regions as $region)
	{
		$targets[] = $canvas . '#xywh=' . $region->xywh;
	}

	if (count($targets) == 0)
	{
		return [];
	}

	$make = function($id, $targets) use ($annotation)
	{
		$output = new stdclass;
		$output->{'@context'} = 'http://www.w3.org/ns/anno.jsonld';

		if ($id)
		{
			$output->id = $id;
		}

		$output->type = 'Annotation';
		$output->motivation = 'highlighting';
		$output->body = $annotation->body;
		$output->target = (count($targets) == 1) ? $targets[0] : $targets;

		return $output;
	};

	if ($split && count($targets) > 1)
	{
		$output = [];

		foreach ($targets as $index => $target)
		{
			$output[] = $make($id ? $id . '/' . $index : null, [$target]);
		}

		return $output;
	}

	return [$make($id, $targets)];
}

//----------------------------------------------------------------------------------------
// CSS position for a normalised [x0, y0, x1, y1] box
function block_style($bbox)
{
	return 'left:' . round($bbox[0] * 100, 3) . '%;'
		. 'top:' . round($bbox[1] * 100, 3) . '%;'
		. 'width:' . round(($bbox[2] - $bbox[0]) * 100, 3) . '%;'
		. 'height:' . round(($bbox[3] - $bbox[1]) * 100, 3) . '%;';
}

//----------------------------------------------------------------------------------------
// Quick visual check: draw the matched rectangles over a page.
//
// $rows is a list of objects with ->value (what was annotated) and ->regions (from
// span_to_regions()), and optionally ->text (what the page text says) and ->distance (non-zero
// for approximate matches, which are drawn in a different colour). If $image is given the
// rectangles are drawn over the page image, otherwise over grey boxes for the words.
function annotation_preview_page($page, $rows, $image = null, $heading = '')
{
	$html = '<div class="page">';

	if ($heading != '')
	{
		$html .= '<h2>' . htmlspecialchars($heading) . '</h2>';
	}

	// The sheet has the page's shape, so the boxes are right even before an image loads
	$html .= '<div class="sheet" style="aspect-ratio:' . (int)$page->width . '/' . (int)$page->height . '">';

	if ($image)
	{
		$html .= '<img src="' . htmlspecialchars($image) . '" alt="">';
	}
	else
	{
		foreach ($page->blocks as $block)
		{
			if ($block->type == 'word')
			{
				$html .= '<div class="word" style="' . block_style($block->bbox) . '"></div>';
			}
		}
	}

	foreach ($rows as $row)
	{
		$class = (isset($row->distance) && $row->distance > 0) ? 'hit near' : 'hit';

		foreach ($row->regions as $region)
		{
			$html .= '<div class="' . $class . '" title="' . htmlspecialchars($row->value . ' [' . $region->text . ']')
				. '" style="' . block_style($region->bbox) . '"></div>';
		}
	}

	$html .= '</div>';

	// List what was matched, so near misses can be checked by eye
	$html .= '<table><tr><th>Annotation</th><th>Text</th><th>Distance</th><th>xywh</th></tr>';

	foreach ($rows as $row)
	{
		$html .= '<tr' . ((isset($row->distance) && $row->distance > 0) ? ' class="near"' : '') . '>'
			. '<td>' . htmlspecialchars($row->value) . '</td>'
			. '<td>' . htmlspecialchars(isset($row->text) ? $row->text : '') . '</td>'
			. '<td>' . (isset($row->distance) ? $row->distance : '') . '</td>'
			. '<td>' . implode('<br>', array_map(function($region) { return $region->xywh; }, $row->regions)) . '</td>'
			. '</tr>';
	}

	$html .= '</table></div>';

	return $html;
}

//----------------------------------------------------------------------------------------
// Wrap one or more pages from annotation_preview_page() in an HTML document
function annotation_preview_html($pages)
{
	return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Annotation preview</title><style>
body { background:rgb(242,242,242); font-family:sans-serif; }
.page { margin:1em auto; max-width:1000px; }
.sheet { position:relative; background:white; border:1px solid rgb(192,192,192); }
.sheet img { display:block; width:100%; height:100%; }
.word { position:absolute; background:rgb(224,224,224); }
.hit { position:absolute; background:rgba(255,0,0,0.25); outline:2px solid red; }
.hit.near { background:rgba(255,140,0,0.25); outline-color:darkorange; }
table { border-collapse:collapse; margin:0.5em 0 2em 0; font-size:0.9em; }
th, td { text-align:left; padding:2px 8px; border-bottom:1px solid rgb(208,208,208); }
tr.near td { color:darkorange; }
</style></head><body>' . implode("\n", $pages) . '</body></html>';
}

?>
