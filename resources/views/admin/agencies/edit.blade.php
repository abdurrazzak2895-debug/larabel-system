@extends('layouts.panel')

@section('title', 'Edit Agency')
@section('page-title', 'Edit Agency')

@section('content')
<div class="max-w-2xl">
    <div class="flex items-center gap-3 mb-6">
        <a href="{{ route('admin.agencies.index') }}" class="text-slate-400 hover:text-slate-600 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div>
            <h2 class="text-xl font-bold text-slate-900">Edit Agency</h2>
            <p class="text-sm text-slate-500 mt-0.5">Update details for <span class="font-medium text-slate-700">{{ $agency->name }}</span>.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-6 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
            <p class="font-semibold mb-1">Please fix the following:</p>
            <ul class="list-disc list-inside space-y-0.5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
        <form method="POST" action="{{ route('admin.agencies.update', $agency) }}" class="space-y-5">
            @csrf
            @method('PUT')

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Agency Name</label>
                <input type="text" name="name" id="name" required value="{{ old('name', $agency->name) }}"
                    class="w-full rounded-xl border-slate-200 text-sm focus:border-brand-500 focus:ring-brand-500">
                @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Agency Code</label>
                <input type="text" value="{{ $agency->code }}" disabled
                    class="w-full rounded-xl border-slate-200 text-sm font-mono bg-slate-50 text-slate-500">
                <p class="text-xs text-slate-400 mt-1">Agency codes cannot be changed after creation.</p>
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="status" value="1" @checked(old('status', $agency->status)) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span class="text-sm text-slate-700">Active</span>
            </label>

            <div class="pt-3 border-t border-slate-100 flex items-center gap-3">
                <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-indigo-500 to-fuchsia-500 hover:from-indigo-600 hover:to-fuchsia-600 text-white text-sm font-semibold rounded-xl shadow-lg shadow-indigo-500/20 transition">
                    Save Changes
                </button>
                <a href="{{ route('admin.agencies.index') }}"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-xl hover:bg-slate-50 transition">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
@endsection
