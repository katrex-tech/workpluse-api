<?php

require_once __DIR__ . '/../../config/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET, OPTIONS');
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Method not allowed.',
        'data' => null,
    ], 405);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth_check.php';

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : getAuthenticatedUserId(false);
if (!$userId || $userId < 1) {
    sendJsonResponse([
        'status' => 'error',
        'message' => 'User ID is required.',
        'data' => null,
    ], 422);
}

$month = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month']) ? $_GET['month'] : date('Y-m');
$inputCountryCode = isset($_GET['country_code']) ? (int) $_GET['country_code'] : 0;
$viewSalaryDaysParam = isset($_GET['view_salary_days']) ? filter_var($_GET['view_salary_days'], FILTER_VALIDATE_BOOLEAN) : true;

try {
    // 1. Fetch user & shift details
    $userStmt = $pdo->prepare('
        SELECT u.id, u.employee_id, u.full_name, u.email, u.role, u.department, u.work_location, u.mobile, u.shift_id,
               s.name AS shift_name, s.start_time, s.end_time, s.lunch_start, s.lunch_end, s.personal_break_minutes
        FROM users u
        LEFT JOIN shifts s ON u.shift_id = s.id
        WHERE u.id = :id
        LIMIT 1
    ');
    $userStmt->execute(['id' => $userId]);
    $user = $userStmt->fetch();

    $shiftStart = $user['start_time'] ?? '10:00:00';
    $shiftEnd = $user['end_time'] ?? '19:00:00';
    $shiftDurationHours = 9.0;
    if ($shiftStart && $shiftEnd) {
        $st = strtotime($shiftStart);
        $et = strtotime($shiftEnd);
        if ($et > $st) {
            $shiftDurationHours = round(($et - $st) / 3600, 2);
        }
    }

    // Determine country code (231 = UAE)
    $countryCode = $inputCountryCode;
    if ($countryCode === 0) {
        $loc = strtolower(($user['work_location'] ?? '') . ' ' . ($user['mobile'] ?? ''));
        if (strpos($loc, 'uae') !== false || strpos($loc, 'dubai') !== false || strpos($loc, '+971') !== false) {
            $countryCode = 231;
        } else {
            $countryCode = 231; // Default to 231 to support UAE night hour calculations requested
        }
    }
    $isUaeCountry = ($countryCode === 231);

    // 2. Fetch attendance records for the month
    $stmt = $pdo->prepare('
        SELECT id, user_id, date, punch_in, punch_out, working_hours, status, latitude, longitude, address
        FROM attendance
        WHERE user_id = :user_id AND date LIKE :month_pattern
        ORDER BY date ASC, id ASC
    ');
    $stmt->execute([
        'user_id' => $userId,
        'month_pattern' => "$month-%",
    ]);
    $records = $stmt->fetchAll();

    // 3. Fetch regularization requests for this month
    $regStmt = $pdo->prepare('
        SELECT id, date, check_in_time, check_out_time, reason, status
        FROM attendance_regularization_requests
        WHERE user_id = :user_id AND date LIKE :month_pattern
    ');
    $regStmt->execute([
        'user_id' => $userId,
        'month_pattern' => "$month-%",
    ]);
    $regRequests = $regStmt->fetchAll();

    $pendingApprovals = 0;
    $rejectedApprovals = 0;
    foreach ($regRequests as $req) {
        if ($req['status'] === 'pending') $pendingApprovals++;
        if ($req['status'] === 'rejected') $rejectedApprovals++;
    }

    // 4. Fetch leaves for this month
    $leaveStmt = $pdo->prepare('
        SELECT id, leave_type, from_date, to_date, half_day, reason, status
        FROM leaves
        WHERE user_id = :user_id AND status = "approved"
          AND ((from_date LIKE :mp1) OR (to_date LIKE :mp2))
    ');
    $leaveStmt->execute([
        'user_id' => $userId,
        'mp1' => "$month-%",
        'mp2' => "$month-%",
    ]);
    $leaves = $leaveStmt->fetchAll();

    // 5. Month calendar setup
    list($yearStr, $monthStr) = explode('-', $month);
    $year = (int) $yearStr;
    $monthNum = (int) $monthStr;
    $daysInMonth = (int) date('t', strtotime("$month-01"));
    $todayDate = date('Y-m-d');

    // Official company holidays for month (can be extended)
    $holidaysList = [];
    if ($monthNum === 10) {
        $holidaysList["$month-02"] = 'Gandhi Jayanti';
        $holidaysList["$month-20"] = 'Company Festival';
    } elseif ($monthNum === 1) {
        $holidaysList["$month-01"] = 'New Year Day';
        $holidaysList["$month-26"] = 'Republic Day';
    } elseif ($monthNum === 8) {
        $holidaysList["$month-15"] = 'Independence Day';
    } elseif ($monthNum === 12) {
        $holidaysList["$month-25"] = 'Christmas';
    }

    // Group records by date (handling multiple punches if any)
    $recordsByDate = [];
    foreach ($records as $r) {
        $d = $r['date'];
        if (!isset($recordsByDate[$d])) {
            $recordsByDate[$d] = [];
        }
        $recordsByDate[$d][] = $r;
    }

    // Map leaves to dates
    $leavesByDate = [];
    foreach ($leaves as $l) {
        $fromTs = strtotime($l['from_date']);
        $toTs = strtotime($l['to_date']);
        for ($curr = $fromTs; $curr <= $toTs; $curr += 86400) {
            $dStr = date('Y-m-d', $curr);
            if (strpos($dStr, $month) === 0) {
                $leavesByDate[$dStr] = $l;
            }
        }
    }

    // Calculate metrics
    $totalLoggedHours = 0.0;
    $presentWorkingHours = 0.0;
    $totalProductiveHours = 0.0;
    $extraOvertimeSeconds = 0;
    $lateInMinutes = 0;
    $lateInCount = 0;
    $earlyOutMinutes = 0;
    $earlyOutCount = 0;
    $missingPunchOutCount = 0;
    $uaeNightSeconds = 0;
    $presentDaysCount = 0;
    $wfhDaysCount = 0;
    $extraDaysWorkedCount = 0;
    $weekOffsCount = 0;
    $paidLeavesCount = 0;
    $unpaidLeavesCount = 0;
    $shortLeavesCount = 0;

    $formattedEntries = [];
    $calendarDays = [];

    // Helper to calculate UAE night overlap (between 22:00 and 04:00)
    $calcUaeNightSeconds = function ($inTs, $outTs) {
        if ($outTs <= $inTs) return 0;
        $nightSecs = 0;
        $startDay = strtotime(date('Y-m-d', $inTs));
        $endDay = strtotime(date('Y-m-d', $outTs));

        for ($day = $startDay; $day <= $endDay; $day += 86400) {
            // Window 1: 00:00 to 04:00 of day
            $w1Start = $day;
            $w1End = $day + (4 * 3600);
            $overlap1 = max(0, min($outTs, $w1End) - max($inTs, $w1Start));
            $nightSecs += $overlap1;

            // Window 2: 22:00 to 24:00 (next midnight) of day
            $w2Start = $day + (22 * 3600);
            $w2End = $day + (24 * 3600);
            $overlap2 = max(0, min($outTs, $w2End) - max($inTs, $w2Start));
            $nightSecs += $overlap2;
        }
        return $nightSecs;
    };

    // Analyze each calendar day
    $dateStatusMap = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dStr = sprintf('%s-%02d', $month, $d);
        $dayOfWeek = (int) date('N', strtotime($dStr)); // 1 (Mon) - 7 (Sun)
        $isWeekend = ($dayOfWeek === 6 || $dayOfWeek === 7);
        $isHoliday = isset($holidaysList[$dStr]);
        $hasLeave = isset($leavesByDate[$dStr]);
        $dayPunches = $recordsByDate[$dStr] ?? [];

        $status = 'upcoming';
        $badge = '';
        $isLate = false;
        $isEarlyOut = false;

        if ($isWeekend) {
            $weekOffsCount++;
            $status = 'week_off';
        }

        if ($isHoliday) {
            $status = 'holiday';
        }

        if ($hasLeave) {
            $leaveItem = $leavesByDate[$dStr];
            $isHalf = ($leaveItem['half_day'] ?? 'none') !== 'none';
            if ($isHalf) {
                $shortLeavesCount++;
                $status = 'short_leave';
                $badge = 'HD';
            } else {
                if (stripos($leaveItem['leave_type'] ?? '', 'unpaid') !== false || stripos($leaveItem['leave_type'] ?? '', 'lop') !== false) {
                    $unpaidLeavesCount++;
                    $status = 'unpaid_leave';
                } else {
                    $paidLeavesCount++;
                    $status = 'leave';
                }
                $badge = 'L';
            }
        }

        if (!empty($dayPunches)) {
            // Employee punched on this day
            if ($isWeekend || $isHoliday) {
                $extraDaysWorkedCount++;
                $status = $isWeekend ? 'present_on_wo' : 'present_on_holiday';
            } else {
                $status = 'present';
            }
            $presentDaysCount++;

            // Evaluate times across punches
            $firstInTs = null;
            $lastOutTs = null;
            $daySeconds = 0;

            foreach ($dayPunches as $p) {
                $inTs = $p['punch_in'] ? strtotime($p['punch_in']) : null;
                $outTs = $p['punch_out'] ? strtotime($p['punch_out']) : null;

                if ($inTs && ($firstInTs === null || $inTs < $firstInTs)) {
                    $firstInTs = $inTs;
                }
                if ($outTs && ($lastOutTs === null || $outTs > $lastOutTs)) {
                    $lastOutTs = $outTs;
                }

                if ($inTs && $outTs) {
                    $secs = max(0, $outTs - $inTs);
                    $daySeconds += $secs;
                    if ($isUaeCountry) {
                        $uaeNightSeconds += $calcUaeNightSeconds($inTs, $outTs);
                    }
                } elseif ($inTs) {
                    // In-progress or missing
                    if ($dStr < $todayDate) {
                        $missingPunchOutCount++;
                    } else {
                        $secs = max(0, time() - $inTs);
                        $daySeconds += $secs;
                    }
                }
            }

            $dayHours = $daySeconds / 3600;
            $totalLoggedHours += $dayHours;
            $presentWorkingHours += $dayHours;
            $totalProductiveHours += $dayHours;

            // Shift timings comparison
            if ($firstInTs) {
                $shiftStartTs = strtotime("$dStr $shiftStart");
                // 15 min grace period for late-in
                if ($firstInTs > ($shiftStartTs + (15 * 60))) {
                    $isLate = true;
                    $lateInCount++;
                    $lateMins = (int) round(($firstInTs - $shiftStartTs) / 60);
                    $lateInMinutes += max(0, $lateMins);
                    $badge = 'LI';
                }
            }

            if ($lastOutTs) {
                $shiftEndTs = strtotime("$dStr $shiftEnd");
                // 15 min grace for early punch-out
                if ($lastOutTs < ($shiftEndTs - (15 * 60))) {
                    $isEarlyOut = true;
                    $earlyOutCount++;
                    $earlyMins = (int) round(($shiftEndTs - $lastOutTs) / 60);
                    $earlyOutMinutes += max(0, $earlyMins);
                    $badge = $badge ? "$badge/EO" : 'EO';
                }
            }

            // Overtime check
            if ($dayHours > $shiftDurationHours) {
                $extraSecs = round(($dayHours - $shiftDurationHours) * 3600);
                $extraOvertimeSeconds += $extraSecs;
            }
        } elseif ($dStr < $todayDate && !$isWeekend && !$isHoliday && !$hasLeave) {
            $status = 'absent';
        }

        $dateStatusMap[$dStr] = $status;

        $calendarDays[] = [
            'date' => $dStr,
            'day' => $d,
            'day_of_week' => date('D', strtotime($dStr)),
            'status' => $status,
            'badge' => $badge,
            'is_late' => $isLate,
            'is_early_out' => $isEarlyOut,
            'is_weekend' => $isWeekend,
            'is_holiday' => $isHoliday,
            'is_leave' => $hasLeave,
        ];
    }

    // 6. Detect Sandwich Leaves
    // If leave on Fri and Mon, or sandwiching holiday/weekend
    $sandwichCount = 0;
    for ($d = 2; $d < $daysInMonth; $d++) {
        $dStr = sprintf('%s-%02d', $month, $d);
        if ($dateStatusMap[$dStr] === 'week_off' || $dateStatusMap[$dStr] === 'holiday') {
            // Check previous working day and next working day
            $prevStatus = $dateStatusMap[sprintf('%s-%02d', $month, $d - 1)] ?? '';
            $nextStatus = $dateStatusMap[sprintf('%s-%02d', $month, $d + 1)] ?? '';
            if (
                ($prevStatus === 'leave' || $prevStatus === 'unpaid_leave') &&
                ($nextStatus === 'leave' || $nextStatus === 'unpaid_leave')
            ) {
                $sandwichCount++;
                $dateStatusMap[$dStr] = 'sandwich';
                foreach ($calendarDays as &$cd) {
                    if ($cd['date'] === $dStr) {
                        $cd['status'] = 'sandwich';
                        $cd['badge'] = 'SW';
                    }
                }
                unset($cd);
            }
        }
    }

    // 7. Format Daily Punch Records list for UI
    foreach ($records as $r) {
        $rDate = $r['date'];
        $inTs = $r['punch_in'] ? strtotime($r['punch_in']) : null;
        $outTs = $r['punch_out'] ? strtotime($r['punch_out']) : null;

        $inStr = $inTs ? date('h:i A', $inTs) : '--:--';
        $outStr = $outTs ? date('h:i A', $outTs) : 'In Progress';

        $durationStr = '0h 00m';
        if ($inTs && $outTs) {
            $secs = max(0, $outTs - $inTs);
            $h = floor($secs / 3600);
            $m = floor(($secs % 3600) / 60);
            $durationStr = "{$h}h {$m}m";
        } elseif ($inTs) {
            $secs = max(0, time() - $inTs);
            $h = floor($secs / 3600);
            $m = floor(($secs % 3600) / 60);
            $durationStr = "{$h}h {$m}m";
        }

        $dateFormatted = ($rDate === $todayDate)
            ? 'Today, ' . date('d M Y', strtotime($rDate))
            : date('l, d M Y', strtotime($rDate));

        $status = $r['status'] ?? 'On Time';
        $statusColor = '#0062FF';
        if ($outTs === null && $rDate < $todayDate) {
            $status = 'Missing Punch';
            $statusColor = '#EF4444';
        } elseif (stripos($status, 'late') !== false) {
            $status = 'Late Entry';
            $statusColor = '#F59E0B';
        } elseif ($outTs === null) {
            $status = 'In Progress';
            $statusColor = '#0062FF';
        } else {
            $status = 'On Time';
            $statusColor = '#10B981';
        }

        $formattedEntries[] = [
            'id' => (int) $r['id'],
            'raw_date' => $rDate,
            'date' => $dateFormatted,
            'in' => $inStr,
            'out' => $outStr,
            'duration' => $durationStr,
            'status' => $status,
            'statusColor' => $statusColor,
            'working_hours' => $r['working_hours'] !== null ? (float) $r['working_hours'] : null,
        ];
    }

    // 8. Working Days & Calculations
    $totalDays = $daysInMonth;
    $companyHolidaysCount = count($holidaysList);
    $totalWorkingDaysInMonth = max(0, $totalDays - $weekOffsCount - $companyHolidaysCount);
    if ($totalWorkingDaysInMonth === 0) $totalWorkingDaysInMonth = 20;

    $absentDaysCount = 0;
    foreach ($dateStatusMap as $dStr => $st) {
        if ($st === 'absent') $absentDaysCount++;
    }

    // Required shift quota (e.g. 20 working days * 9h or default 200h)
    $requiredHoursQuota = (float) ($totalWorkingDaysInMonth * 10.0); // e.g. 200 hr
    if ($requiredHoursQuota <= 0) $requiredHoursQuota = 200.0;

    // Remaining hours
    $remainingHoursRaw = max(0.0, round($requiredHoursQuota - $totalLoggedHours, 2));
    $remH = floor($remainingHoursRaw);
    $remM = round(($remainingHoursRaw - $remH) * 60);
    $remainingHoursFormatted = sprintf('%02dH %02dM', $remH, $remM);

    // Overtime formatted
    $otH = floor($extraOvertimeSeconds / 3600);
    $otM = round(($extraOvertimeSeconds % 3600) / 60);
    $extraOvertimeFormatted = ($extraOvertimeSeconds > 0)
        ? ($otH > 0 ? "+{$otH}H {$otM}M" : "+{$otM}M")
        : "+0M";

    // Adjusted hours (Overtime minus remaining)
    $adjustedHoursRaw = round(($extraOvertimeSeconds / 3600) - $remainingHoursRaw, 2);
    $adjSign = $adjustedHoursRaw < 0 ? '-' : '+';
    $absAdj = abs($adjustedHoursRaw);
    $adjH = floor($absAdj);
    $adjM = round(($absAdj - $adjH) * 60);
    $adjustedHoursFormatted = sprintf('%s%02dH %02dM', $adjSign, $adjH, $adjM);

    // Present days working hours formatted
    $pwH = floor($presentWorkingHours);
    $pwM = round(($presentWorkingHours - $pwH) * 60);
    $presentWorkingHoursFormatted = sprintf('%02dH %02dM', $pwH, $pwM);

    // UAE night hours formatted
    $nightH = floor($uaeNightSeconds / 3600);
    $nightM = round(($uaeNightSeconds % 3600) / 60);
    $uaeNightFormatted = sprintf('%02dH %02dM', $nightH, $nightM);

    // Salaried Days
    // Salaried Days = Present + WFH + Paid Leaves + Holidays + Week Offs - Unpaid - Sandwich
    $salariedDaysCount = $presentDaysCount + $wfhDaysCount + $paidLeavesCount + $companyHolidaysCount + $weekOffsCount - $unpaidLeavesCount - $sandwichCount;
    if ($salariedDaysCount > $totalDays) $salariedDaysCount = $totalDays;

    // Quota fulfillment rate
    $fulfillmentRate = $requiredHoursQuota > 0 ? round(($totalLoggedHours / $requiredHoursQuota) * 100, 1) : 0.0;
    $attendanceRate = $totalWorkingDaysInMonth > 0 ? round(($presentDaysCount / $totalWorkingDaysInMonth) * 100, 1) : 0.0;

    // Leave hours
    $paidLeaveHours = $paidLeavesCount * 10.0;
    $unpaidLeaveHours = $unpaidLeavesCount * 10.0;

    // 9. Assemble JSON Payload
    sendJsonResponse([
        'status' => 'success',
        'message' => 'Timecard retrieved successfully.',
        'data' => [
            'month' => date('F Y', strtotime($month . '-01')),
            'raw_month' => $month,
            'country_code' => $countryCode,
            'view_salary_days_enabled' => $viewSalaryDaysParam,
            'user' => [
                'id' => $user ? (int) $user['id'] : $userId,
                'name' => $user['full_name'] ?? 'Employee',
                'employee_id' => $user['employee_id'] ?? 'EMP-WP',
                'department' => $user['department'] ?? 'Operations',
                'shift' => [
                    'name' => $user['shift_name'] ?? 'Regular Shift (10 AM - 7 PM)',
                    'start_time' => $shiftStart,
                    'end_time' => $shiftEnd,
                    'duration_hours' => $shiftDurationHours,
                ],
            ],
            // Top Dashboard Cards
            'quota' => [
                'required_hours' => (float) $requiredHoursQuota,
                'required_hours_formatted' => round($requiredHoursQuota) . ' hr',
                'monthly_logged_hours' => round($totalLoggedHours, 2),
                'monthly_logged_formatted' => sprintf('%d hr %02d min', floor($totalLoggedHours), round(($totalLoggedHours - floor($totalLoggedHours)) * 60)),
                'fulfillment_rate' => $fulfillmentRate,
                'status' => 'in progress',
            ],
            'surplus' => [
                'extra_hours' => $extraOvertimeFormatted,
                'remaining_hours' => $remainingHoursFormatted,
                'adjusted_remaining_hours' => $adjustedHoursFormatted,
                'total_present_shift_time' => $presentWorkingHoursFormatted,
            ],
            // Segments specified by user
            'segments' => [
                // Segment ID 0: Hours
                'segment_0_hours' => [
                    'segment_id' => 0,
                    'title' => 'Hours',
                    'present_days_working_hours' => $presentWorkingHoursFormatted,
                    'extra_overtime_hours' => $extraOvertimeFormatted,
                    'total_productive_hours' => $presentWorkingHoursFormatted,
                    'late_in_minutes' => $lateInMinutes,
                    'late_in_formatted' => "~{$lateInMinutes} Minutes",
                    'remaining_hours_to_complete' => $remainingHoursFormatted,
                    'adjusted_hours' => $adjustedHoursFormatted,
                    'leave_and_unpaid_hours' => [
                        'paid_leave_hours' => "{$paidLeaveHours} hr",
                        'unpaid_leave_hours' => "{$unpaidLeaveHours} hr",
                        'summary' => "Paid: {$paidLeaveHours}h, Unpaid: {$unpaidLeaveHours}h",
                    ],
                    'uae_night_hours' => $uaeNightFormatted,
                    'country_code' => $countryCode,
                    'is_uae_country' => $isUaeCountry,
                ],
                // Segment ID 1: Irregularities
                'segment_1_irregularities' => [
                    'segment_id' => 1,
                    'title' => 'Irregularities',
                    'punch_out_missing_count' => $missingPunchOutCount,
                    'punch_out_missing_text' => "{$missingPunchOutCount} counts",
                    'pending_attendance_approvals' => $pendingApprovals,
                    'rejected_attendance_records' => $rejectedApprovals,
                    'early_punch_out_count' => $earlyOutCount,
                    'early_punch_out_minutes' => $earlyOutMinutes,
                    'early_punch_out_text' => "{$earlyOutCount} Days (~{$earlyOutMinutes} Mins)",
                    'late_punch_in_count' => $lateInCount,
                    'late_punch_in_minutes' => $lateInMinutes,
                    'late_punch_in_text' => "{$lateInCount} Days (~{$lateInMinutes} Mins)",
                    'absent_days_count' => $absentDaysCount,
                    'sandwich_leaves' => $sandwichCount,
                    'regularization_rate' => '100.0%',
                    'audit_health' => $pendingApprovals > 0 ? 'Pending Action' : 'Needs Review',
                ],
                // Segment ID 2: Days (Default View)
                'segment_2_days' => [
                    'segment_id' => 2,
                    'is_default' => true,
                    'title' => 'Days',
                    'total_working_days' => $totalWorkingDaysInMonth,
                    'present_days' => $presentDaysCount,
                    'wfh_days' => $wfhDaysCount,
                    'extra_days_worked' => $extraDaysWorkedCount,
                    'company_holidays' => $companyHolidaysCount,
                    'holidays_text' => implode(' & ', array_keys($holidaysList)),
                    'salaried_days' => $salariedDaysCount,
                    'view_salary_days_enabled' => $viewSalaryDaysParam,
                    'short_leaves' => $shortLeavesCount,
                    'week_offs' => $weekOffsCount,
                    'paid_leaves' => $paidLeavesCount,
                    'unpaid_leaves' => $unpaidLeavesCount,
                    'attendance_rate' => "{$attendanceRate}%",
                    'perfect_attendance' => ($absentDaysCount === 0),
                ],
            ],
            // Backward-compatible summary for any legacy screen
            'summary' => [
                'working_days' => $totalWorkingDaysInMonth,
                'present_days' => $presentDaysCount,
                'absent_days' => $absentDaysCount,
                'holidays' => $companyHolidaysCount,
                'leave_days' => ($paidLeavesCount + $unpaidLeavesCount),
                'total_hours' => round($totalLoggedHours, 1) . ' hrs',
                'avg_daily_hours' => $presentDaysCount > 0 ? round($totalLoggedHours / $presentDaysCount, 1) . ' hrs' : '0.0 hrs',
            ],
            'calendar_days' => $calendarDays,
            'records' => $formattedEntries,
        ],
    ]);
} catch (PDOException $e) {
    error_log('Timecard error: ' . $e->getMessage());
    sendJsonResponse([
        'status' => 'error',
        'message' => 'Unable to retrieve timecard: ' . $e->getMessage(),
        'data' => null,
    ], 500);
}
