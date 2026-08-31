// fantasy.recipes -- site-wide vanilla JS. No build step, no framework --
// see architecture.md -- Stack Overview.
(function () {
    'use strict';

    var STORAGE_KEY = 'fantasy-recipes-theme';
    var root = document.documentElement;

    function applyTheme(theme) {
        if (theme === 'dark' || theme === 'light') {
            root.setAttribute('data-theme', theme);
        } else {
            root.removeAttribute('data-theme');
        }
    }

    function currentTheme() {
        var stored = null;
        try {
            stored = window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            // localStorage unavailable (private browsing etc.) -- fall
            // back to whatever the browser prefers for this page load.
        }

        if (stored === 'dark' || stored === 'light') {
            return stored;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    applyTheme(currentTheme());

    // ---- Theme: Light/Dark buttons in the "Menu" dropdown ----------------
    //
    // partials/_menu.twig carries two buttons, data-set-theme="light" /
    // "dark", which set the theme directly and persist it. (There is no
    // header toggle any more -- the Menu dropdown is the only nav.)
    var setThemeButtons = document.querySelectorAll('[data-set-theme]');

    function reflectActiveTheme() {
        var active = currentTheme();
        Array.prototype.forEach.call(setThemeButtons, function (button) {
            button.setAttribute(
                'aria-pressed',
                button.getAttribute('data-set-theme') === active ? 'true' : 'false'
            );
        });
    }

    if (setThemeButtons.length) {
        reflectActiveTheme();
        Array.prototype.forEach.call(setThemeButtons, function (button) {
            button.addEventListener('click', function () {
                var mode = button.getAttribute('data-set-theme');
                applyTheme(mode);
                try {
                    window.localStorage.setItem(STORAGE_KEY, mode);
                } catch (e) {
                    // Ignore -- theme just won't persist across visits.
                }
                reflectActiveTheme();
            });
        });
    }

    // Native <details> stays open on outside click -- close it, so the
    // landing "Menu" behaves like a menu.
    document.addEventListener('click', function (event) {
        var openMenu = document.querySelector('details.landing-menu[open]');
        if (openMenu && !openMenu.contains(event.target)) {
            openMenu.removeAttribute('open');
        }
    });

    // ---- Shared: CSRF token rotation after an AJAX POST --------------------
    //
    // slim/csrf tokens are single-use (Guard deletes a token from its pool
    // the moment it validates successfully -- see Guard::process(), and this
    // app never enables persistentTokenMode). Every form on a given page load
    // shares the same token pair, so any AJAX POST that consumes one has to
    // hand back the fresh pair Guard already minted onto the response, and
    // every csrf_name/csrf_value input on the page needs patching to it --
    // otherwise the next action (another row's delete, the tags form's own
    // submit) fails CSRF validation. Used by both the recipe row delete and
    // the tag-picker's inline "create tag" below.
    function patchCsrfTokens(data) {
        var nameInputs = document.querySelectorAll('input[name="csrf_name"]');
        var valueInputs = document.querySelectorAll('input[name="csrf_value"]');
        Array.prototype.forEach.call(nameInputs, function (input) {
            input.value = data.csrf_name;
        });
        Array.prototype.forEach.call(valueInputs, function (input) {
            input.value = data.csrf_value;
        });
    }

    // ---- Admin: /admin/recipes row delete (AJAX) --------------------------
    //
    // Progressive enhancement over a plain <form method="post"> (see
    // templates/admin/recipes_index.twig) -- with JS disabled this just
    // submits normally and the server redirects back to the list as usual.
    // With JS, it removes the row in place instead, per an explicit request
    // that deleting shouldn't reload/re-paginate the whole (potentially
    // 200-row) list. The X-Requested-With header is how the server tells
    // this apart from that plain-form fallback (and from the edit page's
    // own delete button, which is deliberately NOT wired up here -- there's
    // no row to remove there, the whole page has to navigate away).
    var recipeDeleteForms = document.querySelectorAll('.recipe-delete-form');
    Array.prototype.forEach.call(recipeDeleteForms, function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var title = form.getAttribute('data-recipe-title') || 'this recipe';
            var confirmed = window.confirm(
                'Delete "' + title + '"? This removes its Story and tags too. This cannot be undone.'
            );
            if (!confirmed) {
                return;
            }

            var row = form.closest('.recipe-row');

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Delete request failed with status ' + response.status);
                }
                return response.json();
            }).then(function (data) {
                patchCsrfTokens(data);

                if (row && row.parentNode) {
                    row.parentNode.removeChild(row);
                }
            }).catch(function () {
                window.alert('Could not delete this recipe. Please try again.');
            });
        });
    });

    // ---- Admin: recipe tags pill picker ------------------------------------
    //
    // Progressive enhancement over the plain checkbox list in
    // templates/admin/recipe_edit.twig's Tags section: those checkboxes stay
    // in the DOM as the actual form state (name="tag_ids[]") -- this just
    // hides them and drives their `checked` state from a pill UI layered on
    // top, so a JS-disabled browser still gets a fully working (if plainer)
    // tag selector with its own "Save tags" button. With JS, every pill
    // add/remove instead saves straight to POST /admin/recipes/{id}/tags
    // (see that route's XMLHttpRequest branch) and the button is hidden --
    // there's nothing left for it to do. New tags are created inline via
    // POST /admin/tags (see that route's XMLHttpRequest branch too), which
    // both creates the row and hands back the fresh CSRF pair the create
    // request consumed.
    var tagPickers = document.querySelectorAll('.tag-picker');
    Array.prototype.forEach.call(tagPickers, function (picker) {
        var optionsBox = picker.querySelector('.tag-picker-options');
        var checkboxes = Array.prototype.slice.call(optionsBox.querySelectorAll('input[type="checkbox"]'));
        var createUrl = picker.getAttribute('data-create-url');
        var form = picker.closest('form');
        var emptyNotice = picker.querySelector('.tag-picker-empty');
        var saveButton = form ? form.querySelector('.tag-picker-save') : null;
        var status = form ? form.querySelector('.tag-picker-status') : null;

        optionsBox.hidden = true;
        if (emptyNotice) {
            emptyNotice.hidden = true;
        }
        if (saveButton) {
            saveButton.hidden = true;
        }

        // Every AJAX POST this widget makes -- saving the tag set, creating
        // a new tag -- is chained onto this one promise rather than fired
        // independently, so a second request that gets triggered before an
        // earlier one's response comes back waits for it instead of racing
        // it. That matters for two reasons: an older response shouldn't be
        // able to land after and clobber a newer one, and (the sharper
        // issue) the CSRF token each request reads out of the form is only
        // valid *after* the previous request's response has patched it in
        // (see patchCsrfTokens -- tokens are single-use) -- two requests
        // reading the same not-yet-rotated token would send the same value
        // and one would fail CSRF validation. Each task reads the form
        // fresh when it actually runs (inside its .then), rather than
        // capturing it up front, which is what makes that safe.
        var queue = Promise.resolve();

        function enqueue(task) {
            queue = queue.then(task);
            return queue;
        }

        function setStatus(text, isError) {
            if (!status) {
                return;
            }
            status.textContent = text;
            status.classList.toggle('tag-picker-status-error', !!isError);
        }

        function scheduleSave() {
            if (!form) {
                return;
            }

            enqueue(function () {
                setStatus('Saving…', false);

                return fetch(form.getAttribute('action'), {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                }).then(function (result) {
                    patchCsrfTokens(result.data);

                    if (!result.ok || !result.data.success) {
                        setStatus('Could not save tags.', true);
                        return;
                    }

                    setStatus('Saved.', false);
                }).catch(function () {
                    setStatus('Could not save tags.', true);
                });
            });
        }

        var pills = document.createElement('div');
        pills.className = 'tag-pills';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'tag-picker-input';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        input.placeholder = 'Add or create a tag…';

        var suggestions = document.createElement('ul');
        suggestions.className = 'tag-picker-suggestions';
        suggestions.hidden = true;

        pills.appendChild(input);
        picker.appendChild(pills);
        picker.appendChild(suggestions);

        function labelFor(checkbox) {
            // Checkbox label text minus the input itself, e.g.
            // "<input> Dessert" -> "Dessert".
            return checkbox.parentNode.textContent.trim();
        }

        function renderPills() {
            var existingPills = pills.querySelectorAll('.tag-pill');
            Array.prototype.forEach.call(existingPills, function (pill) {
                pill.parentNode.removeChild(pill);
            });

            checkboxes.filter(function (cb) {
                return cb.checked;
            }).forEach(function (cb) {
                var pill = document.createElement('span');
                pill.className = 'tag-pill';

                var name = document.createElement('span');
                name.textContent = labelFor(cb);
                pill.appendChild(name);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'tag-pill-remove';
                remove.setAttribute('aria-label', 'Remove tag ' + labelFor(cb));
                remove.textContent = '×';
                remove.addEventListener('click', function () {
                    cb.checked = false;
                    renderPills();
                    scheduleSave();
                    input.focus();
                });
                pill.appendChild(remove);

                pills.insertBefore(pill, input);
            });
        }

        function closeSuggestions() {
            suggestions.hidden = true;
            suggestions.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
        }

        function selectTag(checkbox) {
            checkbox.checked = true;
            renderPills();
            input.value = '';
            closeSuggestions();
            input.focus();
            scheduleSave();
        }

        function createTag(name) {
            if (!createUrl || !form) {
                return;
            }

            input.disabled = true;
            setStatus('Creating tag…', false);

            // Queued rather than fired straight away -- see the `queue`
            // comment above -- so this can't race an in-flight save (or
            // another create) for the same single-use CSRF token. The
            // form's csrf inputs are read inside the task, once it's this
            // request's turn, so they're always whatever the *previous*
            // queued request last patched them to.
            enqueue(function () {
                var formData = new FormData();
                formData.append('name', name);
                var csrfName = form.querySelector('input[name="csrf_name"]');
                var csrfValue = form.querySelector('input[name="csrf_value"]');
                if (csrfName) {
                    formData.append('csrf_name', csrfName.value);
                }
                if (csrfValue) {
                    formData.append('csrf_value', csrfValue.value);
                }

                return fetch(createUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                }).then(function (result) {
                    input.disabled = false;
                    patchCsrfTokens(result.data);

                    if (!result.ok || !result.data.success) {
                        setStatus(result.data.error || 'Could not create that tag.', true);
                        input.focus();
                        return;
                    }

                    var tag = result.data.tag;
                    var checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.name = 'tag_ids[]';
                    checkbox.value = String(tag.id);
                    checkbox.checked = true;

                    var label = document.createElement('label');
                    label.className = 'tag-option';
                    label.appendChild(checkbox);
                    label.appendChild(document.createTextNode(' ' + tag.name));
                    optionsBox.appendChild(label);

                    checkboxes.push(checkbox);
                    if (emptyNotice) {
                        emptyNotice.hidden = true;
                    }

                    // selectTag() itself calls scheduleSave() (also
                    // queued), which is what actually attaches this new
                    // tag to the recipe -- create-tag only creates the row.
                    selectTag(checkbox);
                }).catch(function () {
                    input.disabled = false;
                    setStatus('Could not create that tag.', true);
                });
            });
        }

        function updateSuggestions() {
            var query = input.value.trim();
            suggestions.innerHTML = '';

            var matches = checkboxes.filter(function (cb) {
                return !cb.checked && labelFor(cb).toLowerCase().indexOf(query.toLowerCase()) !== -1;
            });

            var exactMatch = checkboxes.some(function (cb) {
                return labelFor(cb).toLowerCase() === query.toLowerCase();
            });

            if (query === '' && matches.length === 0) {
                closeSuggestions();
                return;
            }

            matches.forEach(function (cb) {
                var item = document.createElement('li');
                item.setAttribute('role', 'option');
                item.className = 'tag-picker-suggestion';
                item.textContent = labelFor(cb);
                item.addEventListener('mousedown', function (event) {
                    // mousedown, not click -- fires before the input's blur,
                    // so blur-triggered closeSuggestions() below can't race
                    // this and remove the item out from under the click.
                    event.preventDefault();
                    selectTag(cb);
                });
                suggestions.appendChild(item);
            });

            if (query !== '' && !exactMatch && createUrl) {
                var createItem = document.createElement('li');
                createItem.setAttribute('role', 'option');
                createItem.className = 'tag-picker-suggestion tag-picker-suggestion-create';
                createItem.textContent = 'Create tag “' + query + '”';
                createItem.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    createTag(query);
                });
                suggestions.appendChild(createItem);
            }

            var hasItems = suggestions.children.length > 0;
            suggestions.hidden = !hasItems;
            input.setAttribute('aria-expanded', hasItems ? 'true' : 'false');
        }

        input.addEventListener('input', updateSuggestions);
        input.addEventListener('focus', updateSuggestions);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                var first = suggestions.querySelector('.tag-picker-suggestion');
                if (first) {
                    first.dispatchEvent(new Event('mousedown'));
                }
            } else if (event.key === 'Escape') {
                closeSuggestions();
            } else if (event.key === 'Backspace' && input.value === '') {
                var lastPill = pills.querySelectorAll('.tag-pill');
                var last = lastPill[lastPill.length - 1];
                if (last) {
                    last.querySelector('.tag-pill-remove').click();
                }
            }
        });

        input.addEventListener('blur', function () {
            // Delay so a suggestion's mousedown handler (which calls
            // preventDefault but still needs this blur to have fired last)
            // has already run before the list disappears.
            window.setTimeout(closeSuggestions, 100);
        });

        picker.addEventListener('click', function (event) {
            if (event.target === picker || event.target === pills) {
                input.focus();
            }
        });

        renderPills();
    });
})();
