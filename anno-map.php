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
// Build a W3C/IIIF annotation whose target is the list of rectangles for this annotation.
//
// Multiple rectangles are expressed as an array of targets, one per line, which is what
// IIIF viewers such as Mirador understand. Set $split to get one annotation per rectangle
// instead (safer with fussier viewers).
function iiif_annotation($annotation, $canvas, $regions, $id = null, $split = false)
{
	$targets = [];

	foreach ($regions as $index => $region)
	{
		$target = new stdclass;
		$target->type = 'SpecificResource';
		$target->source = $canvas;

		$selector = new stdclass;
		$selector->type = 'FragmentSelector';
		$selector->conformsTo = 'http://www.w3.org/TR/media-frags/';
		$selector->value = 'xywh=' . $region->xywh;

		$target->selector = $selector;

		$targets[] = $target;
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

	if ($split)
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

?>
