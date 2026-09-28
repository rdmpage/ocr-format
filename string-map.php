<?php

require_once(dirname(__FILE__) . '/anno-map.php');

//----------------------------------------------------------------------------------------
// Locate strings on a page when all we know is "this string occurs on this page" (no
// offsets, no surrounding text), so that they can be turned into xywh annotations with the
// functions in anno-map.php. Taxonomic names are the main use, but nothing here is
// specific to them: place names, people, specimen codes and so on work the same way.
//
// Matching is done on tokens (runs of letters and digits), case-insensitively, comparing
// the tokens with the spaces squeezed out. That one comparison covers:
//
// - exact matches, including "ABARATHA" for "Abaratha";
// - strings the OCR has split ("Aba ratha") or hyphenated over a line break
//   ("Aba-\nratha"), by also trying windows one token longer than the string;
// - strings the OCR has run together ("Xusaus" for "Xus aus"), by trying one token shorter;
// - mangled strings, by edit distance.
//
// Punctuation is ignored, so "St. Helena" also matches "St Helena".
//
// If a string has exact matches on the page we take all of them. Only if it has none do
// we accept close matches, and then only the closest ones, on the grounds that someone
// has told us the string is there, so the nearest thing to it is probably it.
//
// Where strings overlap, the longer one (more tokens) wins, so "Xus" is only annotated
// where it isn't part of "Xus aus", and "Wales" not where it is part of "New South Wales".
//
//----------------------------------------------------------------------------------------
// Split text into tokens with character (not byte) offsets, to match the block spans
function text_tokens($text)
{
	$tokens = [];

	preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches, PREG_OFFSET_CAPTURE);

	$byte_offset = 0;
	$char_offset = 0;

	foreach ($matches[0] as $match)
	{
		// Advance the character count incrementally rather than re-measuring from 0
		$char_offset += mb_strlen(substr($text, $byte_offset, $match[1] - $byte_offset));
		$byte_offset = $match[1];

		$token = new stdclass;
		$token->text = $match[0];
		$token->norm = mb_strtolower($match[0]);
		$token->start = $char_offset;
		$token->end = $char_offset + mb_strlen($match[0]);

		$tokens[] = $token;
	}

	return $tokens;
}

//----------------------------------------------------------------------------------------
// Find a string in a list of page tokens.
//
// Returns a list of matches (objects with ->value, ->start, ->end, ->distance, ->size),
// possibly overlapping each other. $max_ratio is the largest edit distance we accept as a
// fraction of the string's length, so short strings never match approximately.
function find_string($tokens, $value, $max_ratio = 0.3)
{
	$value_tokens = array_map(function($token) { return $token->norm; }, text_tokens($value));
	$k = count($value_tokens);

	if ($k == 0)
	{
		return [];
	}

	$target = implode('', $value_tokens);
	$target_length = mb_strlen($target);
	$max_distance = (int)floor($target_length * $max_ratio);

	$candidates = [];

	for ($size = max(1, $k - 1); $size <= $k + 1; $size++)
	{
		for ($i = 0; $i + $size <= count($tokens); $i++)
		{
			$window = '';

			for ($j = $i; $j < $i + $size; $j++)
			{
				$window .= $tokens[$j]->norm;
			}

			if (abs(mb_strlen($window) - $target_length) > $max_distance)
			{
				continue;
			}

			// levenshtein() works on bytes, so an accented letter costs more than one edit
			$distance = ($window === $target) ? 0 : levenshtein($window, $target);

			if ($distance > $max_distance)
			{
				continue;
			}

			$match = new stdclass;
			$match->value = $value;
			$match->start = $tokens[$i]->start;
			$match->end = $tokens[$i + $size - 1]->end;
			$match->distance = $distance;
			$match->size = $k;

			$candidates[] = $match;
		}
	}

	if (count($candidates) == 0)
	{
		return [];
	}

	// Exact matches if there are any, otherwise the closest
	$best = min(array_map(function($match) { return $match->distance; }, $candidates));

	return array_values(array_filter($candidates, function($match) use ($best)
	{
		return $match->distance == $best;
	}));
}

//----------------------------------------------------------------------------------------
// Find a list of strings on a page, resolving overlaps.
//
// Longer strings (more tokens) win over shorter ones, then closer matches over worse ones,
// so "Xus" inside "Xus aus" is dropped, as are duplicate hits on the same text from the
// different window sizes. Returns non-overlapping matches in page order, each with ->text
// set to what the OCR actually says.
function find_strings($page, $values, $max_ratio = 0.3)
{
	$tokens = text_tokens($page->text);

	$candidates = [];

	foreach (array_unique($values) as $value)
	{
		foreach (find_string($tokens, $value, $max_ratio) as $match)
		{
			$candidates[] = $match;
		}
	}

	usort($candidates, function($a, $b)
	{
		if ($a->size != $b->size)
		{
			return $b->size - $a->size;
		}

		if ($a->distance != $b->distance)
		{
			return $a->distance - $b->distance;
		}

		// Prefer the tighter span (e.g. "Abaratha" over "Abaratha Forewing")
		return ($a->end - $a->start) - ($b->end - $b->start);
	});

	$accepted = [];

	foreach ($candidates as $match)
	{
		$overlaps = false;

		foreach ($accepted as $other)
		{
			if ($match->start < $other->end && $match->end > $other->start)
			{
				$overlaps = true;
				break;
			}
		}

		if (!$overlaps)
		{
			$match->text = mb_substr($page->text, $match->start, $match->end - $match->start);
			$accepted[] = $match;
		}
	}

	usort($accepted, function($a, $b) { return $a->start - $b->start; });

	return $accepted;
}

?>
