<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Lab Report Processor
            </h2>
            <div id="action-buttons" class="flex items-center space-x-3">
                </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-screen-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg border border-gray-200">
                <div class="p-6 grid grid-cols-1 lg:grid-cols-2 gap-6" style="height: calc(100vh - 200px);">

                    <div id="pdf-viewer-container" class="border border-gray-300 rounded-lg flex flex-col bg-gray-50">
                        <div class="flex items-center justify-center h-full" id="pdf-upload-placeholder">
                            <div class="text-center">
                                <svg class="w-24 h-24 mx-auto text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <h3 class="mt-2 text-sm font-medium text-gray-900">Upload a Lab Report</h3>
                                <p class="mt-1 text-sm text-gray-500">Get started by uploading a PDF document.</p>
                                <div class="mt-6">
                                    <input type="file" id="pdf-upload-input" class="hidden" accept=".pdf" />
                                    <button type="button" onclick="document.getElementById('pdf-upload-input').click()" class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Select PDF File
                                    </button>
                                </div>
                            </div>
                        </div>
                        <iframe id="pdf-iframe" class="w-full h-full" style="display: none;"></iframe>
                    </div>

                    <div id="data-panel" class="border border-gray-300 rounded-lg flex flex-col">
                        <div class="flex-grow p-6 overflow-y-auto" id="data-display-area">
                           <div class="flex items-center justify-center h-full">
                                <div class="text-center text-gray-500">
                                    <svg class="w-24 h-24 mx-auto text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                    <p class="mt-4">Extracted data will appear here.</p>
                                </div>
                           </div>
                        </div>
                         <div id="raw-json-container" class="p-4 border-t border-gray-200" style="display: none;">
                            <details>
                                <summary class="cursor-pointer text-sm font-medium text-gray-600">View Raw JSON Output</summary>
                                <pre id="raw-json-output" class="mt-2 p-4 bg-gray-800 text-white rounded-md text-xs overflow-x-auto max-h-64"></pre>
                            </details>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const pdfUploadInput = document.getElementById('pdf-upload-input');
            const pdfViewerContainer = document.getElementById('pdf-viewer-container');
            const pdfUploadPlaceholder = document.getElementById('pdf-upload-placeholder');
            const pdfIframe = document.getElementById('pdf-iframe');
            const dataDisplayArea = document.getElementById('data-display-area');
            const actionButtons = document.getElementById('action-buttons');
            let uploadedFile = null;

            pdfUploadInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (file && file.type === 'application/pdf') {
                    uploadedFile = file;
                    const fileUrl = URL.createObjectURL(file);
                    pdfIframe.src = fileUrl;
                    pdfIframe.style.display = 'block';
                    pdfUploadPlaceholder.style.display = 'none';
                    updateActionButtons('extract');
                }
            });

            function updateActionButtons(state) {
                actionButtons.innerHTML = ''; // Clear existing buttons
                if (state === 'extract') {
                    const extractButton = document.createElement('button');
                    extractButton.innerHTML = 'Extract Data';
                    extractButton.className = 'px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors shadow-sm';
                    extractButton.onclick = handleExtractData;
                    actionButtons.appendChild(extractButton);
                } else if (state === 'save') {
                     const saveButton = document.createElement('button');
                    saveButton.innerHTML = 'Save Verified Data';
                    saveButton.className = 'px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors shadow-sm';
                    saveButton.onclick = handleSaveData;
                    actionButtons.appendChild(saveButton);
                }
            }

            function handleExtractData() {
                if (!uploadedFile) {
                    alert('Please upload a PDF file first.');
                    return;
                }

                const extractButton = actionButtons.querySelector('button');
                extractButton.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                extractButton.disabled = true;

                const formData = new FormData();
                formData.append('pdf_file', uploadedFile);

                fetch("{{ route('report.process') }}", {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                            'Accept': 'application/json',
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.message || 'Processing failed.') });
                        }
                        return response.json();
                    })
                    .then(result => {
                        if (result.success) {
                            populateDataPanel(result.data);
                            updateActionButtons('save');
                        } else {
                            throw new Error(result.message || 'Could not extract data from the document.');
                        }
                    })
                    .catch(error => {
                        alert('Error: ' + error.message);
                        extractButton.innerHTML = 'Extract Data';
                        extractButton.disabled = false;
                    });
            }
            
            function handleSaveData() {
                // This is where you would collect the data from the form fields
                // and send it to a new endpoint to save in the database.
                alert('Save functionality to be implemented!');
            }

            function populateDataPanel(data) {
                dataDisplayArea.innerHTML = ''; // Clear placeholder
                
                // Display Patient & Lab Info
                dataDisplayArea.innerHTML += createInfoSection('Patient Information', data.patientInfo, 'user');
                dataDisplayArea.innerHTML += createInfoSection('Lab Information', data.labInfo, 'beaker');
                
                // Display Test Results
                dataDisplayArea.innerHTML += createTestResultsSection(data.testResults);

                // Display Raw JSON
                document.getElementById('raw-json-output').textContent = JSON.stringify(data, null, 2);
                document.getElementById('raw-json-container').style.display = 'block';
            }
            
            function createInfoSection(title, infoObject, icon) {
                let fieldsHtml = '';
                for (const [key, value] of Object.entries(infoObject)) {
                    const label = key.replace(/([A-Z])/g, ' $1').replace(/^./, str => str.toUpperCase());
                    fieldsHtml += `
                        <div class="sm:col-span-1">
                            <label for="${key}" class="block text-sm font-medium text-gray-500">${label}</label>
                            <input type="text" name="${key}" id="${key}" value="${value || ''}" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                        </div>
                    `;
                }
                
                const iconSvg = icon === 'user'
                    ? `<svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>`
                    : `<svg class="h-6 w-6 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>`;

                return `
                    <div class="mb-6">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 flex items-center mb-4">
                           ${iconSvg}
                            <span class="ml-3">${title}</span>
                        </h3>
                        <div class="mt-2 grid grid-cols-1 gap-y-4 gap-x-4 sm:grid-cols-2">
                           ${fieldsHtml}
                        </div>
                    </div>`;
            }

            function createTestResultsSection(results) {
                 if (!results || results.length === 0) return '<p class="text-gray-500">No test results found.</p>';
                 
                 let rowsHtml = '';
                 results.forEach(item => {
                     rowsHtml += `
                        <tr class="test-result-row">
                            <td class="px-2 py-2 whitespace-nowrap text-sm font-medium text-gray-900">${item.category}</td>
                            <td class="px-2 py-2 whitespace-nowrap text-sm text-gray-500"><input type="text" value="${item.testName || ''}" class="w-full border-none rounded-md p-1 focus:ring-1 focus:ring-blue-500"></td>
                            <td class="px-2 py-2 whitespace-nowrap text-sm text-gray-500"><input type="text" value="${item.result || ''}" class="w-full border-none rounded-md p-1 focus:ring-1 focus:ring-blue-500"></td>
                            <td class="px-2 py-2 whitespace-nowrap text-sm text-gray-500"><input type="text" value="${item.unit || ''}" class="w-full border-none rounded-md p-1 focus:ring-1 focus:ring-blue-500"></td>
                            <td class="px-2 py-2 whitespace-nowrap text-sm text-gray-500"><input type="text" value="${item.referenceRange || ''}" class="w-full border-none rounded-md p-1 focus:ring-1 focus:ring-blue-500"></td>
                            <td class="px-2 py-2 whitespace-nowrap text-sm text-gray-500"><input type="text" value="${item.flag || ''}" class="w-20 border-none rounded-md p-1 focus:ring-1 focus:ring-blue-500"></td>
                        </tr>
                     `;
                 });

                 return `
                    <h3 class="text-lg leading-6 font-medium text-gray-900 flex items-center mb-4">
                        <svg class="h-6 w-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" /></svg>
                        <span class="ml-3">Test Results</span>
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                    <th scope="col" class="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Test</th>
                                    <th scope="col" class="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Result</th>
                                    <th scope="col" class="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                                    <th scope="col" class="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reference</th>
                                    <th scope="col" class="px-2 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Flag</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                ${rowsHtml}
                            </tbody>
                        </table>
                    </div>
                 `;
            }
        });
    </script>
</x-app-layout>