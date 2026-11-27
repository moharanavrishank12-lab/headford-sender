@extends('layout')
@section('body')
<div class="box" style="max-width:360px;margin:80px auto">
<h1>HeadFord Sender</h1>
@if(session('err'))<div class="alert">{{ session('err') }}</div>@endif
<form method="post" action="/login">@csrf
<input type="password" name="password" placeholder="Password" style="width:100%;padding:8px;margin-bottom:10px" autofocus>
<button style="width:100%;padding:8px">Login</button></form></div>
@endsection
