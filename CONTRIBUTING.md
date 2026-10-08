# Contributing to IndicSearch

Thank you for your interest in improving **IndicSearch**!

IndicSearch is dedicated to building state-of-the-art search algorithms and typo-tolerance tooling tailored for Indian languages (Devanagari, Hindi, Hinglish, Marathi, Sanskrit, Nepali, and other Indic scripts).

## How You Can Contribute

1. **Expanding Synonym Dictionaries**: Add common Hinglish colloquialisms, dialectal spelling variations, or domain vocabularies to `src/Dictionary/data/`.
2. **Improving NLP & Stemming**: Enhance phonetic similarity matrices, grapheme cluster handling, or morphological stemmers in `src/NLP/`.
3. **Database Drivers**: Add support for PostgreSQL or SQLite while maintaining the fast O(1) skeleton lookup.
4. **Performance Benchmarking**: Benchmark indexing speeds and latency across large corpora.

## Development Workflow

1. Fork the repository and clone it locally.
2. Ensure you have PHP 7.4+ and MySQL/MariaDB.
3. Run the unit tests:
   ```bash
   php tests/test_nlp.php
   ```
4. Run the integration tests:
   ```bash
   php tests/test_integration.php
   ```
5. Submit a pull request with clear explanations and test cases.

## Code Standards
- Adhere to PSR-12 coding guidelines.
- Maintain UTF-8 multi-byte string handling safety across all functions.
- Avoid external binary dependencies so that IndicSearch runs effortlessly on standard shared hosting environments.
