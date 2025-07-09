<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Create New Extraction Template') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <form action="#" method="POST" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                @csrf
                <div class="p-6 bg-white border-b border-gray-200">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 border-b pb-6 mb-6">
                        <div>
                            <label for="template_name" class="block text-sm font-medium text-gray-700">Template Name</label>
                            <input type="text" name="template_name" id="template_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="e.g., KV Hospital Official Report" required>
                        </div>
                        <div>
                            <label for="processor_id" class="block text-sm font-medium text-gray-700">Google Processor ID</label>
                            <input type="text" name="processor_id" id="processor_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" placeholder="Paste Processor ID here" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">AI Detected Fields (Source)</h3>
                            <div class="p-4 border rounded-md bg-gray-50 max-h-96 overflow-y-auto">
                                <h4 class="font-semibold text-gray-700 mb-2">Key-Value Pairs</h4>
                                <div class="text-sm space-y-1">
                                    @foreach($aiData['entities'] as $key => $value)
                                        <div class="font-mono text-xs"><span class="bg-gray-200 px-1 rounded">{{ $key }}</span>: {{ $value }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="space-y-6">
                            <h3 class="text-lg font-medium text-gray-900">Map to Clinex Fields (Destination)</h3>
                            <div class="p-4 border rounded-md max-h-96 overflow-y-auto">
                                <h4 class="font-semibold text-gray-700 mb-2">Patient Information</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    @foreach($clinexFields['patient_info'] as $field)
                                        <div>
                                            <label class="block text-sm font-medium">{{ ucwords(str_replace('_', ' ', $field)) }}</label>
                                            <select name="mappings[patient_info][{{ $field }}]" class="mt-1 block w-full text-sm rounded-md border-gray-300">
                                                <option value="">-- Select AI Field --</option>
                                                @foreach(array_keys($aiData['entities']) as $key)
                                                    <option value="{{ $key }}">{{ $key }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                     <div class="mt-8">
                        <h3 class="text-lg font-medium text-gray-900 border-t pt-6">Map Detected Tables</h3>
                        <div class="space-y-6 mt-4">
                            @foreach($aiData['tables'] as $table)
                                <div class="p-4 border rounded-md bg-gray-50">
                                    <h5 class="font-semibold mb-2">{{ $table['name'] }}</h5>
                                    
                                    <div class="text-xs mb-4 overflow-x-auto">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="bg-gray-200">
                                                    @foreach($table['headers'] as $header)
                                                        <th class="p-2 font-mono">{{ $header }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($table['rows'] as $row)
                                                <tr>
                                                    @foreach($row as $cell)
                                                        <td class="border p-2 font-mono">{{ $cell }}</td>
                                                    @endforeach
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700">Assign Section Name</label>
                                            <input type="text" name="mappings[tables][{{ $table['index'] }}][section_name]" class="mt-1 block w-full text-sm rounded-md border-gray-300" placeholder="e.g., biochemistry" required>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mt-4">
                                        @foreach($clinexFields['table_columns'] as $colField)
                                            <div>
                                                <label class="block text-sm font-medium">{{ ucwords(str_replace('_', ' ', $colField)) }}</label>
                                                <select name="mappings[tables][{{ $table['index'] }}][columns][{{ $colField }}]" class="mt-1 block w-full text-sm rounded-md border-gray-300">
                                                    <option value="">-- Ignore --</option>
                                                    @foreach($table['headers'] as $index => $header)
                                                        <option value="{{ $index }}">Column {{ $index + 1 }} ({{ $header }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                </div>
                <div class="px-6 py-4 bg-gray-50 text-right">
                    <button type="submit" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                        Save Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>