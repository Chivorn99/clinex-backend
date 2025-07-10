<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold mb-4">Welcome, {{ Auth::user()->name }}!</h3>
                    
                    <div class="mb-4">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium
                            @if(Auth::user()->isAdmin()) 
                                bg-red-100 text-red-800
                            @else 
                                bg-blue-100 text-blue-800
                            @endif
                        ">
                            {{ ucwords(str_replace('_', ' ', Auth::user()->role)) }}
                        </span>
                    </div>

                    @if(Auth::user()->isAdmin())
                        <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4 mb-4">
                            <h4 class="font-medium text-yellow-800">Administrator Access</h4>
                            <p class="text-yellow-700 text-sm">You have full system access including user management.</p>
                        </div>
                    @endif

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <!-- Upload Lab Reports Card -->
                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg shadow-lg p-6 text-white">
                            <h4 class="text-lg font-semibold mb-2">Upload Lab Reports</h4>
                            <p class="text-blue-100 mb-4">Upload and process new lab reports</p>
                            <a href="{{ route('lab-reports.upload') }}" class="inline-flex items-center px-4 py-2 bg-white text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                                </svg>
                                Upload Reports
                            </a>
                        </div>

                        @can('admin')
                        <!-- Templates Management Card -->
                        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
                            <h4 class="text-lg font-semibold mb-2">Templates</h4>
                            <p class="text-green-100 mb-4">Manage lab report templates</p>
                            <a href="{{ route('templates.index') }}" class="inline-flex items-center px-4 py-2 bg-white text-green-600 rounded-lg hover:bg-green-50 transition-colors">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                Manage Templates
                            </a>
                        </div>
                        @endcan

                        <!-- Recent Reports Card -->
                        <div class="bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg shadow-lg p-6 text-white">
                            <h4 class="text-lg font-semibold mb-2">Recent Reports</h4>
                            <p class="text-purple-100 mb-4">View recent lab reports</p>
                            @php
                                $recentReport = \App\Models\LabReport::latest()->first();
                            @endphp
                            @if($recentReport)
                                <a href="{{ route('lab-reports.correct', $recentReport) }}" class="inline-flex items-center px-4 py-2 bg-white text-purple-600 rounded-lg hover:bg-purple-50 transition-colors">
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    View Latest
                                </a>
                            @else
                                <span class="inline-flex items-center px-4 py-2 bg-white/20 text-white rounded-lg">
                                    No Reports Yet
                                </span>
                            @endif
                        </div>

                        @if(Auth::user()->isAdmin())
                        <!-- User Management Card (Admin Only) -->
                        <div class="bg-gradient-to-r from-red-500 to-red-600 rounded-lg shadow-lg p-6 text-white">
                            <h4 class="text-lg font-semibold mb-2">User Management</h4>
                            <p class="text-red-100 mb-4">Manage system users and roles</p>
                            <a href="#" class="inline-flex items-center px-4 py-2 bg-white text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                Manage Users
                            </a>
                        </div>
                        @endif

                        <!-- Profile Card -->
                        <div class="bg-gradient-to-r from-green-500 to-green-600 rounded-lg shadow-lg p-6 text-white">
                            <h4 class="text-lg font-semibold mb-2">Profile</h4>
                            <p class="text-green-100 mb-4">Update your profile information</p>
                            <a href="{{ route('profile.edit') }}" class="inline-flex items-center px-4 py-2 bg-white text-green-600 rounded-lg hover:bg-green-50 transition-colors">
                                Edit Profile
                            </a>
                        </div>
                    </div>

                    <!-- Quick Actions Section -->
                    <div class="mt-8">
                        <h4 class="text-lg font-semibold mb-4">Quick Actions</h4>
                        <div class="bg-gray-50 rounded-lg p-4">
                            @php
                                $recentReports = \App\Models\LabReport::latest()->take(5)->get();
                            @endphp
                            
                            @if($recentReports->count() > 0)
                                <h5 class="font-medium mb-3">Recent Lab Reports:</h5>
                                <div class="space-y-2">
                                    @foreach($recentReports as $report)
                                        <div class="flex items-center justify-between bg-white p-3 rounded border">
                                            <div>
                                                <span class="font-medium">Report #{{ $report->id }}</span>
                                                <span class="text-sm text-gray-500 ml-2">
                                                    {{ $report->created_at->format('M d, Y') }}
                                                </span>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ml-2
                                                    @if($report->status === 'processed') bg-green-100 text-green-800
                                                    @elseif($report->status === 'processing') bg-yellow-100 text-yellow-800
                                                    @else bg-gray-100 text-gray-800
                                                    @endif
                                                ">
                                                    {{ ucfirst($report->status) }}
                                                </span>
                                            </div>
                                            <a href="{{ route('lab-reports.correct', $report) }}" 
                                               class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                Review →
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="text-gray-600">No lab reports found. Upload some reports to get started!</p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
