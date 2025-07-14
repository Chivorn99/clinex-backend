#!/usr/bin/env python3

import sys
import json
import re
import argparse
from typing import Dict, Any, Optional, List

class LabReportParser:
    def __init__(self):
        self.patient_fields = {
            'name': ['ឈោ្មះ/Name', 'in:/Name', 'nin:/Name'],
            'patient_id': ['Patient ID'],
            'age': ['အာဿြ/Age', 'អាយុ/Age'],  # Both Unicode variations
            'gender': ['ភេទ/Gender'],
            'phone': ['ទូរស័ព្ទ/Phone', 'លេខទូរស័ព្ទ']
        }
        
        self.lab_fields = {
            'lab_id': ['Lab ID'],
            'requested_by': ['Requested By'],
            'requested_date': ['Requested Date'],
            'collected_date': ['Collected Date'],
            'analysis_date': ['Analysis Date'],
            'validated_by': ['Lab Technician', 'Validated By', 'validatedBy']
        }
        
        self.hospital_phone_patterns = [
            '097 840 47 89',
            '012 89 17 45',
            '012 28 60 70'
        ]

    def parse(self, ocr_text: str) -> Dict[str, Any]:
        lines = [line.strip() for line in ocr_text.split('\n') if line.strip()]
        
        # Extract key-value pairs with intelligent field mapping
        field_map = self.extract_scattered_pairs(lines)
        
        # Apply corrections and validations
        field_map = self.apply_corrections(field_map, lines)
        
        return {
            "patientInfo": self.build_patient_info(field_map),
            "labInfo": self.build_lab_info(field_map),
            "testResults": []  # We'll implement this later if needed
        }

    def extract_scattered_pairs(self, lines: List[str]) -> Dict[str, str]:
        """Extract key-value pairs from scattered OCR text"""
        field_map = {}
        
        # Find header boundaries
        header_start = -1
        header_end = len(lines)
        
        for idx, line in enumerate(lines):
            if any(key in line for key_list in self.patient_fields.values() for key in key_list):
                if header_start == -1:
                    header_start = idx
            
            if 'LABORATORY REPORT' in line.upper() or 'BIOCHEMISTRY' in line.upper():
                header_end = idx
                break
        
        if header_start == -1:
            header_start = 0
        
        print(f"DEBUG: Processing header from line {header_start} to {header_end}", file=sys.stderr)
        
        # Process header section
        i = header_start
        processed_lines = set()  # Track processed lines to avoid duplicates
        
        while i < min(header_end, len(lines)):
            if i in processed_lines:
                i += 1
                continue
                
            line = lines[i].strip()
            if not line:
                i += 1
                continue
            
            print(f"DEBUG: Processing line {i}: {line}", file=sys.stderr)
            
            # Skip lines that start with ':' as they are values, not keys
            if line.startswith(':'):
                i += 1
                continue
            
            # Check if this line is a known field key
            if self.is_known_field(line):
                key = line
                value = None
                
                # Look ahead for value (next 1-3 lines)
                for j in range(i + 1, min(i + 4, len(lines))):
                    if j >= len(lines) or j in processed_lines:
                        continue
                    
                    next_line = lines[j].strip()
                    if not next_line:
                        continue
                    
                    if next_line.startswith(':'):
                        value = next_line[1:].strip()
                        processed_lines.add(j)  # Mark value line as processed
                        print(f"DEBUG: Found scattered pair: {key} = {value}", file=sys.stderr)
                        break
                    elif self.is_known_field(next_line):
                        break
                
                if value and not self.is_hospital_phone(key, value):
                    field_map[key] = value
                    processed_lines.add(i)  # Mark key line as processed
            
            # Handle direct key:value pairs on the same line (but not starting with ':')
            elif ':' in line and not line.startswith(':'):
                parts = line.split(':', 1)
                if len(parts) == 2:
                    key, value = parts[0].strip(), parts[1].strip()
                    if key and value and not self.is_hospital_phone(key, value):
                        field_map[key] = value
                        processed_lines.add(i)
                        print(f"DEBUG: Found direct pair: {key} = {value}", file=sys.stderr)
            
            i += 1
        
        return field_map

    def is_known_field(self, text: str) -> bool:
        """Check if text is a known patient/lab field"""
        all_fields = []
        for field_list in self.patient_fields.values():
            all_fields.extend(field_list)
        for field_list in self.lab_fields.values():
            all_fields.extend(field_list)
        
        return text in all_fields

    def is_hospital_phone(self, key: str, value: str) -> bool:
        """Check if this is a hospital phone number to exclude"""
        phone_keys = ['ទូរស័ព្ទ/Phone', 'លេខទូរស័ព្ទ', 'î₪çîñḥ']
        if key in phone_keys:
            for pattern in self.hospital_phone_patterns:
                if pattern in value:
                    return True
        return False

    def apply_corrections(self, field_map: Dict[str, str], lines: List[str]) -> Dict[str, str]:
        """Apply intelligent corrections to fix common OCR mapping issues"""
        corrected = field_map.copy()
        
        print(f"DEBUG: Before corrections: {field_map}", file=sys.stderr)
        
        # Remove empty keys that might have been created
        if '' in corrected:
            del corrected['']
        
        # Fix missing age - look for age patterns in the text
        age_found = any(key in corrected for key in self.patient_fields['age'])
        if not age_found:
            for line in lines:
                # Look for age pattern like "58 Y" or ": 58 Y"
                age_match = re.search(r':\s*(\d+\s*Y)', line)
                if age_match:
                    age_value = age_match.group(1).strip()
                    # Make sure this isn't part of a date or other field
                    if 'Y' in age_value and len(age_value) < 10:
                        corrected['អាយុ/Age'] = age_value
                        print(f"DEBUG: Found missing age: {age_value}", file=sys.stderr)
                        break
        
        # Fix misplaced names
        name_found = any(key in corrected for key in self.patient_fields['name'])
        
        if not name_found:
            for key, value in field_map.items():
                # Look for name patterns (all caps, no numbers, not Dr.)
                if re.match(r'^[A-Z][A-Z\s]+$', value) and not re.search(r'\d', value) and 'Dr.' not in value:
                    if key in ['Requested Date', 'Analysis Date', 'Collected Date']:
                        print(f"DEBUG: Moving misplaced name '{value}' from '{key}' to name field", file=sys.stderr)
                        corrected['ឈោ្មះ/Name'] = value
                        # Find correct date for this field
                        correct_date = self.find_date_for_field(key, lines)
                        if correct_date:
                            corrected[key] = correct_date
                        else:
                            del corrected[key]  # Remove incorrect mapping
                        break
        
        # Fix dates that contain non-date values
        date_fields = ['Requested Date', 'Collected Date', 'Analysis Date']
        for field in date_fields:
            if field in corrected:
                value = corrected[field]
                if not re.match(r'\d{2}/\d{2}/\d{4}', value):
                    print(f"DEBUG: Fixing date field '{field}' with invalid value '{value}'", file=sys.stderr)
                    
                    # Check if it's gender info
                    if value.lower() in ['male', 'female']:
                        if 'ភេទ/Gender' not in corrected:
                            corrected['ភេទ/Gender'] = value
                    
                    # Find correct date
                    correct_date = self.find_date_for_field(field, lines)
                    if correct_date:
                        corrected[field] = correct_date
                    else:
                        del corrected[field]
        
        # Fix missing Patient ID
        if 'Patient ID' not in corrected:
            for line in lines:
                pt_match = re.search(r'PT\d+', line)
                if pt_match:
                    corrected['Patient ID'] = pt_match.group(0)
                    break
        
        # Fix missing Lab ID
        if 'Lab ID' not in corrected:
            for line in lines:
                lt_match = re.search(r'LT\d+', line)
                if lt_match:
                    corrected['Lab ID'] = lt_match.group(0)
                    break
        
        # Fix phone number
        phone_keys = ['ទូរស័ព្ទ/Phone', 'លេខទូរស័ព្ទ']
        phone_found = any(key in corrected for key in phone_keys)
        
        if not phone_found:
            for line in lines:
                phone_match = re.search(r':\s*(0\d{8,9})', line)
                if phone_match:
                    phone = phone_match.group(1)
                    if not any(pattern in phone for pattern in self.hospital_phone_patterns):
                        corrected['ទូរស័ព្ទ/Phone'] = phone
                        break
        
        # Fix doctor name corruption
        if 'Requested By' in corrected:
            doctor_name = corrected['Requested By']
            # Fix common OCR errors in doctor names
            if 'CHHOR' in doctor_name or 'RN' in doctor_name:
                # Look for the original pattern in the text
                for line in lines:
                    dr_match = re.search(r'Dr\.\s+([A-Z]+\s+[A-Za-z]+)', line)
                    if dr_match and 'CHHORN' in dr_match.group(0):
                        corrected['Requested By'] = dr_match.group(0)
                        break
        
        # Fix validated by
        validated_keys = ['Lab Technician', 'Validated By', 'validatedBy']
        validated_found = any(key in corrected for key in validated_keys)
        
        if not validated_found:
            for line in lines:
                # Look for Khmer names or specific patterns
                if re.search(r'(ហុក\s+ម៉េងឆាយ|SREYNEANG\s*-\s*B\.Sc|ផាន\s+ឡាទី)', line):
                    match = re.search(r'(ហុក\s+ម៉េងឆាយ|SREYNEANG\s*-\s*B\.Sc|ផាន\s+ឡាទី)', line)
                    if match:
                        corrected['validatedBy'] = match.group(1)
                        break
        
        print(f"DEBUG: After corrections: {corrected}", file=sys.stderr)
        return corrected

    def find_date_for_field(self, field: str, lines: List[str]) -> Optional[str]:
        """Find the correct date for a specific field using context"""
        all_dates = []
        
        # Extract all dates with context
        for line_idx, line in enumerate(lines):
            date_matches = re.findall(r'\d{2}/\d{2}/\d{4}\s+\d{2}:\d{2}', line)
            for date in date_matches:
                all_dates.append({
                    'date': date,
                    'line_idx': line_idx,
                    'context': line.lower()
                })
        
        # Try to match based on context
        for date_info in all_dates:
            context = date_info['context']
            if field == 'Requested Date' and 'request' in context:
                return date_info['date']
            elif field == 'Collected Date' and 'collect' in context:
                return date_info['date']
            elif field == 'Analysis Date' and 'analysis' in context:
                return date_info['date']
        
        # Fallback: assign dates in order they appear
        if all_dates:
            if field == 'Requested Date':
                return all_dates[0]['date'] if len(all_dates) > 0 else None
            elif field == 'Collected Date':
                return all_dates[1]['date'] if len(all_dates) > 1 else None
            elif field == 'Analysis Date':
                return all_dates[2]['date'] if len(all_dates) > 2 else all_dates[-1]['date']
        
        return None

    def build_patient_info(self, field_map: Dict[str, str]) -> Dict[str, Any]:
        return {
            "name": self.find_value(field_map, self.patient_fields['name']),
            "patientId": self.find_value(field_map, self.patient_fields['patient_id']),
            "age": self.find_value(field_map, self.patient_fields['age']),
            "gender": self.find_value(field_map, self.patient_fields['gender']),
            "phone": self.find_value(field_map, self.patient_fields['phone'])
        }

    def build_lab_info(self, field_map: Dict[str, str]) -> Dict[str, Any]:
        return {
            "labId": self.find_value(field_map, self.lab_fields['lab_id']),
            "requestedBy": self.find_value(field_map, self.lab_fields['requested_by']),
            "requestedDate": self.find_value(field_map, self.lab_fields['requested_date']),
            "collectedDate": self.find_value(field_map, self.lab_fields['collected_date']),
            "analysisDate": self.find_value(field_map, self.lab_fields['analysis_date']),
            "validatedBy": self.find_value(field_map, self.lab_fields['validated_by'])
        }

    def find_value(self, field_map: Dict[str, str], possible_keys: List[str]) -> Optional[str]:
        for key in possible_keys:
            if key in field_map:
                return field_map[key]
        return None

def main():
    parser = argparse.ArgumentParser(description='Parse lab report OCR text')
    parser.add_argument('input_file', help='Path to file containing OCR text')
    parser.add_argument('--output-format', choices=['json', 'pretty'], default='json')
    
    args = parser.parse_args()
    
    try:
        with open(args.input_file, 'r', encoding='utf-8') as f:
            ocr_text = f.read()
        
        lab_parser = LabReportParser()
        result = lab_parser.parse(ocr_text)
        
        if args.output_format == 'json':
            print(json.dumps(result, ensure_ascii=False))
        else:
            print(json.dumps(result, ensure_ascii=False, indent=2))
            
    except Exception as e:
        print(f"Error: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == '__main__':
    main()