<?php

namespace Fromholdio\TextStatistics\Extensions;

use DaveChild\TextStatistics\TextStatistics;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

class TextStatisticsExtension extends DataExtension
{
    private static $is_text_statistics_enabled = true;
    private static $text_statistics_source_field_name = 'ContentPlain';
    private static $text_statistics_tab_path = 'Root.Statistics';

    private static $db = [
        'FKReadingEase' => 'Decimal(5,2)',
        'FKGradeLevel' => 'Decimal(5,2)',
        'ReadingTimeSeconds' => 'Decimal(5,2)',
        'ReadingTimeFriendly' => 'Varchar',
        'ReadabilityScore' => 'Int',
    ];

    public function onAfterPlainTextReset(): void
    {
        $this->getOwner()->doRecalculateTextStatistics();
    }

    public function getTextStatisticsContent(): ?string
    {
        $content = null;
        $fieldName = $this->getOwner()->config()->get('text_statistics_source_field_name');
        if (!empty($fieldName) && $this->getOwner()->hasField($fieldName)) {
            $content = $this->getOwner()->getField($fieldName);
        }
        $this->getOwner()->invokeWithExtensions('updateTextStatisticsContent', $content);
        return $content;
    }

    public function doRecalculateTextStatistics(): void
    {
        $tableName = DataObject::getSchema()->tableForField(
            get_class($this->getOwner()),
            'FKReadingEase'
        );

        if ($this->getOwner()->hasExtension(Versioned::class))
        {
            $stage = Versioned::get_stage();
            if ($stage === Versioned::LIVE) {
                $tableName .= '_Live';
            }
        }

        if (!$this->getOwner()->isTextStatisticsEnabled() || empty($this->getOwner()->getTextStatisticsContent())) {
            $params = [
                0, 0, 0, null, 0,
                $this->getOwner()->getField('ID')
            ];
        } else {
            $params = [
                $this->getOwner()->getFleschKincaidReadingEase(),
                $this->getOwner()->getFleschKincaidGradeLevel(),
                $this->getOwner()->getReadingTimeSeconds(),
                $this->getOwner()->getFriendlyReadingTime(),
                $this->getOwner()->getReadabilityScore(),
                $this->getOwner()->getField('ID')
            ];
        }

        $sql = "UPDATE \"$tableName\" SET \"FKReadingEase\" = ?, \"FKGradeLevel\" = ?, \"ReadingTimeSeconds\" = ?, \"ReadingTimeFriendly\" = ?, \"ReadabilityScore\" = ? WHERE \"ID\" = ?";
        DB::prepared_query($sql, $params);
    }

    public function isTextStatisticsEnabled(): bool
    {
        return (bool) $this->getOwner()->config()->get('is_text_statistics_enabled');
    }

    public function hasTextStatistics(): bool
    {
        return $this->getOwner()->isTextStatisticsEnabled()
            && !empty($this->getOwner()->getTextStatisticsContent());
    }

    public function getFleschKincaidReadingEase(): float
    {
        $stats = new TextStatistics;
        $text = $this->getOwner()->getTextStatisticsContent();
        return (float) $stats->fleschKincaidReadingEase($text);
    }

    public function getFleschKincaidGradeLevel(): float
    {
        $stats = new TextStatistics;
        $text = $this->getOwner()->getTextStatisticsContent();
        return (float) $stats->fleschKincaidGradeLevel($text);
    }

    public function getReadingTimeSeconds(): float
    {
        $stats = new TextStatistics;
        $text = $this->getOwner()->getTextStatisticsContent();
        $wordCount = $stats->wordCount($text);
        $wordsPerSecond = 4.17;
        return round($wordCount / $wordsPerSecond, 2);
    }

    public function getFriendlyReadingTime(): string
    {
        $human = '-';
        $seconds = $this->getOwner()->getReadingTimeSeconds();
        if ($seconds > 0)
        {
            $minutes = floor($seconds / 60);
            $remainSeconds = round((int) $seconds % 60);
            $human = '';
            if ($minutes > 0) $human = $minutes . 'm';
            if ($remainSeconds > 0) {
                if ($minutes > 0) $human .= ' ';
                $human .= $remainSeconds . 's';
            }
        }
        return $human;
    }

    public function getReadabilityScore(): int
    {
        $fkGradeLevel = $this->getOwner()->getFleschKincaidGradeLevel();
        $values[] = floor($fkGradeLevel);
        $values[] = ceil($fkGradeLevel);

        $fkReadingEase = $this->getOwner()->getFleschKincaidReadingEase();
        if ($fkReadingEase < 100 && $fkReadingEase >= 90) {
            $values[] = 5;
        }
        else if ($fkReadingEase < 90 && $fkReadingEase >= 80) {
            $values[] = 6;
        }
        else if ($fkReadingEase < 80 && $fkReadingEase >= 70) {
            $values[] = 7;
        }
        else if ($fkReadingEase < 70 && $fkReadingEase >= 60) {
            $values[] = 8;
            $values[] = 9;
        }
        else if ($fkReadingEase < 60 && $fkReadingEase >= 50) {
            $values[] = 10;
        }
        else if ($fkReadingEase < 50 && $fkReadingEase >= 40) {
            $values[] = 11;
        }
        else if ($fkReadingEase < 40 && $fkReadingEase >= 30) {
            $values[] = 12;
        }
        else {
            $values[] = 13;
        }

        sort($values);
        $valuesCount = count($values);
        $valuesIndex = floor($valuesCount / 2);
        if ($valuesCount & 1) {
            $score = $values[$valuesIndex];
        } else {
            $score = ($values[$valuesIndex - 1] + $values[$valuesIndex]) / 2;
        }
        return (int) round($score);
    }

    public function getTextStatisticsTabPath(): ?string
    {
        return $this->getOwner()->config()->get('text_statistics_tab_path');
    }

    public function updateCMSFields(FieldList $fields): void
    {
        if (!$this->hasTextStatistics()) {
            return;
        }

        $tabPath = $this->getOwner()->getTextStatisticsTabPath();
        if (empty($tabPath)) {
            return;
        }

        $fields->addFieldsToTab(
            $tabPath,
            [
                ReadonlyField::create(
                    'ReadingTimeFriendly',
                    'Reading Time',
                    $this->getOwner()->getFriendlyReadingTime()
                ),
                ReadonlyField::create(
                    'FKReadingEase',
                    'Flesch-Kincaid Reading Ease',
                    $this->getOwner()->getFleschKincaidReadingEase()
                ),
                ReadonlyField::create(
                    'FKGradeLevel',
                    'Flesch-Kincaid Grade Level',
                    $this->getOwner()->getFleschKincaidGradeLevel()
                ),
            ]
        );
    }
}
