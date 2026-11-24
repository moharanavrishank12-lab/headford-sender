<?php
require __DIR__.'/boot.php';
use App\Services\Calendar; use Carbon\CarbonImmutable as C;
$h = array_filter(Calendar::holidays(2026));
ok(in_array('2026-11-26', $h) && in_array('2026-11-27', $h) && in_array('2026-07-03', $h), 'holidays: Thanksgiving, Black Friday, July 4 observed (Fri)');
ok(Calendar::windowOpen('E', C::parse('2026-11-24 13:30', 'UTC')), 'East 8:30 ET Tue = open');
ok(! Calendar::windowOpen('W', C::parse('2026-11-24 13:30', 'UTC')), 'West 5:30 PT = closed');
ok(Calendar::windowOpen('W', C::parse('2026-11-24 16:30', 'UTC')), 'West 8:30 PT = open');
ok(! Calendar::windowOpen('E', C::parse('2026-11-26 14:00', 'UTC')), 'Thanksgiving = closed');
ok(! Calendar::windowOpen('E', C::parse('2026-11-28 14:00', 'UTC')), 'Saturday = closed');
ok((string) Calendar::afterWorkdays('E', 3, C::parse('2026-11-24 14:00', 'UTC')) === '2026-12-01 13:00:00', '3 workdays after Tue 24 Nov (skip Thu/Fri holiday + weekend) = Tue 1 Dec 8 AM ET');
ok(Calendar::rampCap(C::parse('2026-11-24'), 20, C::parse('2026-11-24')) === 5, 'ramp day 1 = 5');
ok(Calendar::rampCap(C::parse('2026-11-16'), 20, C::parse('2026-11-19')) === 14, 'ramp after 3 workdays = 14');
ok(Calendar::rampCap(C::parse('2026-10-01'), 20, C::parse('2026-11-24')) === 20, 'ramp max 20');
done();
