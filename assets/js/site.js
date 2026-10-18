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

    // ---- Conjure a Recipe: draggable reagent pills -----------------------
    //
    // Progressive enhancement over templates/recipes/conjure.twig's two
    // plain <input name="must"> / <input name="can"> search fields. With JS
    // off, the page is just those two comma/space-separated text boxes and
    // the GET form submits exactly as before. With JS, each field is hidden
    // and its tokens become "pills" sorted into a "Must have" and a "Can
    // have" bucket; dragging a pill to the other bucket (or, for touch and
    // keyboard, clicking it / pressing Enter on it) moves it, and every
    // add / remove / move rewrites the hidden field that backs the bucket
    // so the query string the form finally submits is unchanged. There is
    // no AJAX here -- the buckets are purely a nicer way to fill in two
    // strings that still get submitted by the plain GET form.
    var conjureForm = document.querySelector('.conjure-form');
    if (conjureForm) {
        var mustInput = conjureForm.querySelector('#must');
        var canInput = conjureForm.querySelector('#can');

        if (mustInput && canInput) {
            var LABELS = { must: 'Must have', can: 'Can have' };
            var lists = {};
            // The pill currently being dragged, shared between the pill's
            // own dragstart/dragend and each bucket's dragover/drop. HTML5
            // drag-and-drop can only carry strings in its dataTransfer, not
            // a node reference, so we keep the element here instead.
            var dragged = null;

            function tokenize(value) {
                return value.split(/[\s,]+/).filter(function (token) {
                    return token !== '';
                });
            }

            function other(key) {
                return key === 'must' ? 'can' : 'must';
            }

            function bucketOf(pill) {
                return pill.parentNode.getAttribute('data-bucket');
            }

            // Reagent names, in DOM order, from one bucket back into its
            // backing <input> -- space-separated, which tokenize() and the
            // server's own preg_split('/[\s,]+/') both accept.
            function sync() {
                ['must', 'can'].forEach(function (key) {
                    var names = Array.prototype.map.call(
                        lists[key].querySelectorAll('.reagent-pill'),
                        function (pill) { return pill.getAttribute('data-reagent'); }
                    );
                    (key === 'must' ? mustInput : canInput).value = names.join(' ');
                });
            }

            // Case-insensitive so "Chicken" dragged onto a bucket that
            // already lists "chicken" is treated as a duplicate, not a
            // second pill.
            function has(key, name) {
                return Array.prototype.some.call(
                    lists[key].querySelectorAll('.reagent-pill'),
                    function (pill) {
                        return pill.getAttribute('data-reagent').toLowerCase() === name.toLowerCase();
                    }
                );
            }

            function relabel(pill) {
                var key = bucketOf(pill);
                pill.setAttribute(
                    'aria-label',
                    pill.getAttribute('data-reagent') + ', in ' + LABELS[key]
                        + '. Press Enter to move to ' + LABELS[other(key)] + '.'
                );
            }

            // Put a pill into a bucket. If that bucket already has the
            // reagent (e.g. it was dragged onto its own pile, or onto a
            // bucket that independently listed it) the pill is just
            // discarded so we never show the same reagent twice.
            function place(pill, key) {
                if (has(key, pill.getAttribute('data-reagent'))) {
                    if (pill.parentNode) {
                        pill.parentNode.removeChild(pill);
                    }
                } else {
                    lists[key].appendChild(pill);
                    relabel(pill);
                }
                sync();
            }

            function moveToOther(pill) {
                var target = other(bucketOf(pill));
                place(pill, target);
                if (pill.parentNode) {
                    pill.focus();
                }
            }

            function makePill(name) {
                var pill = document.createElement('span');
                pill.className = 'reagent-pill';
                pill.setAttribute('data-reagent', name);
                pill.setAttribute('draggable', 'true');
                pill.setAttribute('tabindex', '0');

                var text = document.createElement('span');
                text.textContent = name;
                pill.appendChild(text);

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'reagent-pill-remove';
                remove.setAttribute('aria-label', 'Remove ' + name);
                remove.textContent = '×';
                remove.addEventListener('click', function (event) {
                    // Stop this from bubbling to the pill's own click
                    // handler, which would "move to other bucket" instead
                    // of removing.
                    event.stopPropagation();
                    pill.parentNode.removeChild(pill);
                    sync();
                });
                pill.appendChild(remove);

                pill.addEventListener('dragstart', function (event) {
                    dragged = pill;
                    pill.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    // Firefox will not start a drag at all unless some data
                    // is set; the value itself is unused (see `dragged`).
                    event.dataTransfer.setData('text/plain', name);
                });
                pill.addEventListener('dragend', function () {
                    pill.classList.remove('is-dragging');
                    dragged = null;
                });

                // Touch and keyboard have no usable HTML5 drag, so a plain
                // click or Enter/Space on the pill moves it to the other
                // bucket -- the only move this two-bucket UI ever needs.
                pill.addEventListener('click', function () {
                    moveToOther(pill);
                });
                pill.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                        event.preventDefault();
                        moveToOther(pill);
                    }
                });

                return pill;
            }

            function addReagents(key, value) {
                tokenize(value).forEach(function (name) {
                    if (!has(key, name)) {
                        var pill = makePill(name);
                        lists[key].appendChild(pill);
                        relabel(pill);
                    }
                });
            }

            function buildBucket(key) {
                var bucket = document.createElement('div');
                bucket.className = 'reagent-bucket';

                var heading = document.createElement('h2');
                heading.className = 'reagent-bucket-title';
                heading.textContent = LABELS[key];
                bucket.appendChild(heading);

                var list = document.createElement('div');
                list.className = 'reagent-bucket-list';
                list.setAttribute('data-bucket', key);
                bucket.appendChild(list);
                lists[key] = list;

                var add = document.createElement('input');
                add.type = 'text';
                add.className = 'reagent-add';
                add.setAttribute('autocomplete', 'off');
                add.setAttribute('aria-label', 'Add a reagent to ' + LABELS[key]);
                add.placeholder = 'Add a reagent…';

                function commit() {
                    addReagents(key, add.value);
                    add.value = '';
                    sync();
                }

                add.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ',') {
                        // Enter here must not submit the form, and a comma
                        // is a separator, not a literal character.
                        event.preventDefault();
                        commit();
                    }
                });
                // Whatever is left half-typed when focus leaves still counts.
                add.addEventListener('blur', commit);
                bucket.appendChild(add);

                function over(event) {
                    if (!dragged) {
                        return;
                    }
                    // preventDefault on dragenter/dragover is what marks an
                    // element as a valid drop target.
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                    bucket.classList.add('drag-over');
                }
                bucket.addEventListener('dragenter', over);
                bucket.addEventListener('dragover', over);
                bucket.addEventListener('dragleave', function (event) {
                    // dragleave also fires when crossing between the
                    // bucket's own children; only clear the highlight when
                    // the pointer has genuinely left the bucket.
                    if (!bucket.contains(event.relatedTarget)) {
                        bucket.classList.remove('drag-over');
                    }
                });
                bucket.addEventListener('drop', function (event) {
                    event.preventDefault();
                    bucket.classList.remove('drag-over');
                    if (dragged) {
                        var pill = dragged;
                        place(pill, key);
                        if (pill.parentNode) {
                            pill.focus();
                        }
                    }
                });

                return bucket;
            }

            var board = document.createElement('div');
            board.className = 'reagent-buckets';
            board.appendChild(buildBucket('must'));
            board.appendChild(buildBucket('can'));

            // Seed the buckets from whatever the two fields arrived with
            // (a resubmitted search, a shared link) before hiding them.
            addReagents('must', mustInput.value);
            addReagents('can', canInput.value);

            var submit = conjureForm.querySelector('button[type="submit"]');
            conjureForm.insertBefore(board, submit);

            var hadAutofocus = mustInput.hasAttribute('autofocus');
            [mustInput, canInput].forEach(function (input) {
                input.hidden = true;
                var label = conjureForm.querySelector('label[for="' + input.id + '"]');
                if (label) {
                    label.hidden = true;
                }
            });

            // #must carried autofocus; hand it to the "Must have" add box
            // now that the input it was on is gone.
            if (hadAutofocus) {
                lists.must.parentNode.querySelector('.reagent-add').focus();
            }

            // Belt-and-braces -- the hidden inputs are already kept in step
            // on every pill change, but re-sync on submit so nothing can
            // slip through.
            conjureForm.addEventListener('submit', sync);
            sync();
        }
    }
})();
