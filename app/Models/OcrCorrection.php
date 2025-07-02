<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OcrCorrection extends Model
{
    protected $fillable = [
        'original_text',
        'corrected_text',
        'correction_type',
        'frequency',
        'confidence_score'
    ];

    public static function learnCorrection(string $original, string $corrected, string $type): void
    {
        if ($original === $corrected)
            return; // No change needed

        $correction = self::firstOrNew([
            'original_text' => $original,
            'corrected_text' => $corrected,
            'correction_type' => $type
        ]);

        if ($correction->exists) {
            // Increase frequency and confidence
            $correction->increment('frequency');
            $correction->confidence_score = min(100, $correction->confidence_score + 2);
        } else {
            // New correction
            $correction->frequency = 1;
            $correction->confidence_score = 85; // Start with good confidence
        }

        $correction->save();
    }

    /**
     * Get best correction for given text
     */
    public static function getBestCorrection(string $text, string $type): ?string
    {
        // Try exact match first
        $correction = self::where('original_text', $text)
            ->where('correction_type', $type)
            ->where('confidence_score', '>=', 80)
            ->orderBy('frequency', 'desc')
            ->first();

        if ($correction) {
            return $correction->corrected_text;
        }

        // Try fuzzy matching for similar texts
        $corrections = self::where('correction_type', $type)
            ->where('confidence_score', '>=', 85)
            ->orderBy('frequency', 'desc')
            ->limit(20) // Only check top 20 most frequent corrections
            ->get();

        foreach ($corrections as $correction) {
            if (self::isSimilarText($text, $correction->original_text)) {
                return $correction->corrected_text;
            }
        }

        return null;
    }

    /**
     * Check if two texts are similar
     */
    private static function isSimilarText(string $text1, string $text2): bool
    {
        // Remove spaces and compare
        $clean1 = str_replace(' ', '', strtolower($text1));
        $clean2 = str_replace(' ', '', strtolower($text2));

        if ($clean1 === $clean2)
            return true;

        // Calculate similarity percentage
        similar_text($clean1, $clean2, $percent);
        return $percent > 85; // 85% similarity threshold
    }
}
