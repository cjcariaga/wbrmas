<?php
function resident_age_from_birth_date($birth_date, ?DateTimeImmutable $today = null) {
    $birth_date = trim((string)$birth_date);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth_date)) return null;
    $parts = explode('-', $birth_date);
    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) return null;

    try {
        $birth = new DateTimeImmutable($birth_date);
        $today = $today ?? new DateTimeImmutable('today');
        if ($birth > $today) return null;
        return (int)$birth->diff($today)->y;
    } catch (Throwable $error) {
        return null;
    }
}

function resident_age_group_from_age($age) {
    if (!is_int($age) || $age < 0) return 'Unknown';
    if ($age <= 1) return 'Infant';
    if ($age <= 12) return 'Children';
    if ($age <= 17) return 'Youth';
    if ($age <= 30) return 'Young Adult';
    if ($age <= 59) return 'Adult';
    return 'Senior Citizen';
}

function resident_age_group_from_birth_date($birth_date, ?DateTimeImmutable $today = null) {
    return resident_age_group_from_age(resident_age_from_birth_date($birth_date, $today));
}