/**
 * IndicSearch Drop-in Autocomplete & Search Client
 * 
 * Lightweight, zero-dependency JavaScript client for IndicSearch API.
 * Features:
 * - Debounced as-you-type live search & autocomplete
 * - Full keyboard navigation (Up, Down, Enter, Escape)
 * - "Did you mean" typo pills click-through
 * - Accessible ARIA combobox pattern
 * - Mobile responsive
 */
(function (global) {
  'use strict';

  class IndicSearchWidget {
    constructor(options = {}) {
      this.inputSelector = options.inputSelector || '#indic-search-input';
      this.containerSelector = options.containerSelector || '#indic-search-container';
      this.apiEndpoint = options.apiEndpoint || '/api/search.php';
      this.debounceMs = options.debounceMs || 250;
      this.minChars = options.minChars || 2;
      this.onSelect = options.onSelect || null;
      this.renderItem = options.renderItem || this.defaultRenderItem;

      this.input = document.querySelector(this.inputSelector);
      this.container = document.querySelector(this.containerSelector);
      this.dropdown = null;
      this.debounceTimer = null;
      this.selectedIndex = -1;
      this.items = [];

      if (!this.input) {
        console.warn('IndicSearch: Input element not found:', this.inputSelector);
        return;
      }

      this.init();
    }

    init() {
      // Create dropdown container if not already created
      this.dropdown = document.createElement('div');
      this.dropdown.className = 'indic-search-dropdown hidden';
      this.dropdown.setAttribute('role', 'listbox');

      if (this.container) {
        this.container.appendChild(this.dropdown);
      } else {
        this.input.parentNode.appendChild(this.dropdown);
      }

      // Bind input events
      this.input.setAttribute('autocomplete', 'off');
      this.input.setAttribute('aria-expanded', 'false');
      this.input.setAttribute('aria-haspopup', 'listbox');

      this.input.addEventListener('input', () => this.handleInput());
      this.input.addEventListener('keydown', (e) => this.handleKeyDown(e));
      this.input.addEventListener('focus', () => {
        if (this.input.value.trim().length >= this.minChars) {
          this.fetchResults(this.input.value.trim(), true);
        }
      });

      // Close dropdown when clicking outside
      document.addEventListener('click', (e) => {
        if (!this.input.contains(e.target) && !this.dropdown.contains(e.target)) {
          this.hide();
        }
      });
    }

    handleInput() {
      clearTimeout(this.debounceTimer);
      const query = this.input.value.trim();

      if (query.length < this.minChars) {
        this.hide();
        return;
      }

      this.debounceTimer = setTimeout(() => {
        this.fetchResults(query, true);
      }, this.debounceMs);
    }

    handleKeyDown(e) {
      if (this.dropdown.classList.contains('hidden')) return;

      const items = this.dropdown.querySelectorAll('.indic-search-item');
      if (items.length === 0) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        this.selectedIndex = (this.selectedIndex + 1) % items.length;
        this.updateSelection(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        this.selectedIndex = (this.selectedIndex - 1 + items.length) % items.length;
        this.updateSelection(items);
      } else if (e.key === 'Enter') {
        if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
          e.preventDefault();
          items[this.selectedIndex].click();
        }
      } else if (e.key === 'Escape') {
        this.hide();
      }
    }

    updateSelection(items) {
      items.forEach((item, index) => {
        if (index === this.selectedIndex) {
          item.classList.add('selected');
          item.scrollIntoView({ block: 'nearest' });
          this.input.setAttribute('aria-activedescendant', item.id || '');
        } else {
          item.classList.remove('selected');
        }
      });
    }

    async fetchResults(query, asYouType = true, noFuzzy = false) {
      try {
        const url = new URL(this.apiEndpoint, window.location.origin);
        url.searchParams.set('q', query);
        url.searchParams.set('as_you_type', asYouType ? '1' : '0');
        if (noFuzzy) {
          url.searchParams.set('no_fuzzy', '1');
        }

        const res = await fetch(url.toString(), {
          headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();

        if (data.success !== false) {
          this.renderDropdown(data, query);
        }
      } catch (err) {
        console.error('IndicSearch fetch error:', err);
      }
    }

    renderDropdown(data, query) {
      this.dropdown.innerHTML = '';
      this.selectedIndex = -1;

      // 1. Did You Mean Banner
      if (data.did_you_mean && data.did_you_mean.length > 0) {
        const dym = data.did_you_mean[0];
        const dymEl = document.createElement('div');
        dymEl.className = 'indic-search-did-you-mean';

        if (dym.type === 'correct') {
          dymEl.innerHTML = `क्या आपका मतलब था: <span class="indic-dym-pill">${this.escape(dym.alt)}</span>?`;
        } else {
          dymEl.innerHTML = `दिखाए जा रहे परिणाम: <strong>${this.escape(dym.alt)}</strong> | <span class="indic-dym-pill">सिर्फ "${this.escape(dym.original)}" खोजें</span>`;
        }

        dymEl.querySelector('.indic-dym-pill')?.addEventListener('click', () => {
          this.input.value = dym.type === 'correct' ? dym.alt : dym.original;
          this.fetchResults(this.input.value, false, dym.type !== 'correct');
        });

        this.dropdown.appendChild(dymEl);
      }

      // 2. Suggestions / Autocomplete Header
      if (data.suggestions && data.suggestions.length > 0) {
        const sugContainer = document.createElement('div');
        sugContainer.className = 'indic-search-suggestions';
        data.suggestions.slice(0, 4).forEach(sug => {
          const pill = document.createElement('button');
          pill.type = 'button';
          pill.className = 'indic-sug-pill';
          pill.textContent = sug;
          pill.addEventListener('click', () => {
            this.input.value = sug;
            this.fetchResults(sug, false);
          });
          sugContainer.appendChild(pill);
        });
        this.dropdown.appendChild(sugContainer);
      }

      // 3. Search Results
      const results = data.results || [];
      if (results.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'indic-search-empty';
        empty.textContent = 'कोई परिणाम नहीं मिला (No results found)';
        this.dropdown.appendChild(empty);
      } else {
        const list = document.createElement('div');
        list.className = 'indic-search-list';

        results.forEach((item, index) => {
          const itemEl = document.createElement('div');
          itemEl.className = 'indic-search-item';
          itemEl.setAttribute('role', 'option');
          itemEl.id = `indic-item-${index}`;
          itemEl.innerHTML = this.renderItem(item, data.terms || [query]);

          itemEl.addEventListener('click', () => {
            if (typeof this.onSelect === 'function') {
              this.onSelect(item);
            } else if (item.url) {
              window.location.href = item.url;
            }
          });

          list.appendChild(itemEl);
        });

        this.dropdown.appendChild(list);
      }

      this.show();
    }

    defaultRenderItem(item) {
      const title = item.highlighted_title || item.title || `Document #${item.id}`;
      const desc = item.highlighted_description || item.description || item.snippet || '';
      return `
        <div class="indic-item-title">${title}</div>
        ${desc ? `<div class="indic-item-desc">${desc}</div>` : ''}
      `;
    }

    escape(str) {
      return (str || '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
      }[m]));
    }

    show() {
      this.dropdown.classList.remove('hidden');
      this.input.setAttribute('aria-expanded', 'true');
    }

    hide() {
      this.dropdown.classList.add('hidden');
      this.input.setAttribute('aria-expanded', 'false');
      this.selectedIndex = -1;
    }
  }

  global.IndicSearchWidget = IndicSearchWidget;
})(typeof window !== 'undefined' ? window : this);
