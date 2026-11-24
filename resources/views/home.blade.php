@extends('layout')
@section('body')
<div style="display:flex;justify-content:space-between;align-items:center"><h1>HeadFord Sender (cloud)</h1><a href="/logout">Logout</a></div>
@if(session('msg'))<div class="msg" style="white-space:pre-wrap">{{ session('msg') }}</div>@endif
@foreach($alerts as $k => $a)<div class="alert">⚠ {{ $a['msg'] }} <span class="muted">({{ \Carbon\Carbon::parse($a['at'])->diffForHumans() }})</span></div>@endforeach
<div class="grid" style="margin-bottom:14px">
  <div class="tile"><span class="muted">Aaj bheje (follow-up milake)</span><b>{{ $s['sent_today'] }} / {{ $s['cap_day'] }}</b></div>
  <div class="tile"><span class="muted">Aaj naye leads</span><b>{{ $s['new_today'] }}</b></div>
  <div class="tile"><span class="muted">Chalu IDs</span><b>{{ $s['active_ids'] }} / {{ count($s['accounts']) }}</b></div>
  <div class="tile"><span class="muted">Stock (bhejne ko tayyar)</span><b>{{ $s['stock'] }}</b><span class="muted">{{ $s['stock_days'] }} din ka ({{ $s['new_per_day'] }} naye/din)</span></div>
  <div class="tile"><span class="muted">Aaj replies</span><b>{{ $s['replies_today'] }}</b></div>
  <div class="tile"><span class="muted">Positive leads (kul)</span><b>{{ $s['positive_total'] }}</b></div>
  <div class="tile"><span class="muted">Haath se dekhna</span><b class="{{ $s['review'] ? 'bad' : '' }}">{{ $s['review'] }}</b></div>
</div>
<div class="box"><h2>Leads ki halat</h2>
@foreach($s['by_status'] as $st => $n)<span style="margin-right:14px">{{ $st }}: <b>{{ $n }}</b></span>@endforeach
@if($s['stuck_reserved'])<div class="alert">{{ $s['stuck_reserved'] }} emails "reserved" mein atke (Gmail se pakka ho rahe hain)</div>@endif
</div>
@if(count($review))
<div class="box"><h2>Haath se dekho (reply samajh nahi aaya)</h2><table>
@foreach($review as $r)<tr><td>{{ $r->from_email }}<div class="muted">{{ $r->created_at->diffForHumans() }}</div></td><td style="white-space:pre-wrap">{{ \Illuminate\Support\Str::limit(\App\Services\Classifier::clean($r->body ?? ''), 400) }}</td>
<td style="white-space:nowrap">@foreach(['INTERESTED' => 'Positive (Mark ko)', 'NOT_NOW' => 'Baad mein', 'NOT_INTERESTED' => 'Mana', 'UNSUBSCRIBE' => 'Unsubscribe'] as $c => $l)
<form method="post" action="/reply/{{ $r->id }}" style="display:inline">@csrf<input type="hidden" name="class" value="{{ $c }}"><button>{{ $l }}</button></form>@endforeach</td></tr>@endforeach
</table></div>@endif
<div class="box"><h2>Sending IDs</h2><table><tr><th>ID</th><th>Halat</th><th>Aaj</th><th>7 din bheje</th><th>Bounce 7 din</th><th>Aakhri</th><th></th></tr>
@foreach($s['accounts'] as $a)<tr>
<td>{{ $a['email'] }}<div class="muted">{{ $a['name'] }}</div></td>
<td class="{{ $a['status'] === 'active' ? 'ok' : 'bad' }}">{{ $a['status'] }}@if($a['paused_reason'])<div class="muted">{{ $a['paused_reason'] }}</div>@endif @if($a['error'])<div class="muted bad">{{ \Illuminate\Support\Str::limit($a['error'], 120) }}</div>@endif</td>
<td>{{ $a['today'] }} / {{ $a['cap'] }}</td><td>{{ $a['sent7'] }}</td><td class="{{ $a['bounce_pct'] > 3 ? 'bad' : '' }}">{{ $a['bounce7'] }} ({{ $a['bounce_pct'] }}%)</td>
<td>{{ $a['last_sent'] ?? '-' }}<div class="muted">inbox: {{ $a['checked'] ?? '-' }}</div></td>
<td><form method="post" action="/account/{{ $a['id'] }}">@csrf<input type="hidden" name="do" value="{{ $a['status'] === 'active' ? 'pause' : 'resume' }}"><button>{{ $a['status'] === 'active' ? 'Roko' : 'Chalu karo' }}</button></form></td></tr>@endforeach
</table>
<details style="margin-top:10px"><summary>IDs jodo</summary><form method="post" action="/accounts">@csrf<div class="muted">Har line: email, naam (jaise: anna@coldmail1.com, Anna Lee). Nayi ID 5/din se shuru hoke roz +3 (20 tak).</div>
<textarea name="lines" rows="6" style="width:100%"></textarea><button>Jodo</button></form></details></div>
<div class="box"><h2>Positive leads (Mark ko handoff)</h2><table><tr><th>#</th><th>Store</th><th>Email</th><th>Halat</th></tr>
@foreach($positives as $l)<tr><td>{{ $l->lead_no }}</td><td>{{ $l->domain }}<div class="muted">{{ $l->first_name }} {{ $l->last_name }}</div></td><td>{{ $l->email }}</td><td>{{ $l->status }}</td></tr>@endforeach</table></div>
<div class="box"><h2>Aakhri replies</h2><table>
@foreach($replies as $r)<tr><td>{{ $r->from_email }}<div class="muted">{{ $r->created_at->diffForHumans() }}</div></td><td>{{ $r->class }}</td><td>{{ $r->summary }}</td><td>{{ $r->action }}</td></tr>@endforeach</table></div>
<div class="box"><h2>Setup</h2>
<div>Google key: @if ($gkey) <b>lagi hui</b> (Client ID: {{ $gkey }} - yahi Workspace admin mein daalna hai) @else <b style="color:#b00">abhi nahi</b> @endif</div>
<form method="post" action="/setup/google-key" enctype="multipart/form-data" style="margin:6px 0">@csrf<input type="file" name="key" accept=".json"> <button>Google key upload</button></form>
<form method="post" action="/setup/check">@csrf<button>Sab IDs check karo</button> <span class="muted">Har ID ka Google access test (koi email nahi jaati).</span></form></div>
<div class="box"><h2>Signature (har email ke neeche)</h2><form method="post" action="/settings">@csrf
<div class="muted">Naam = ID ka naam. Neeche company line aur address (US niyam ke liye address zaroori).</div>
<input name="company_line" value="{{ $settings['company_line'] }}" placeholder="HeadFord" style="width:100%;margin:4px 0">
<input name="address" value="{{ $settings['address'] }}" placeholder="Company address, Brooklyn, NY" style="width:100%;margin:4px 0"><button>Save</button></form></div>
<div class="muted">Page har 2 minute apne aap taaza hota hai.</div><script>setTimeout(()=>location.reload(),120000)</script>
@endsection
