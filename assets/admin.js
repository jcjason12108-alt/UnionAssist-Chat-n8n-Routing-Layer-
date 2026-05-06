jQuery(document).ready(function($) {
    function initIconControl(options) {
        var typeField = $(options.typeSelector);
        if (!typeField.length) return;

        var customRow = $(options.customRowSelector);
        var urlInput = $(options.urlSelector);
        var uploadBtn = $(options.uploadBtnSelector);
        var removeBtn = $(options.removeBtnSelector);
        var previewContainer = $(options.previewSelector);
        var uploader = null;

        typeField.on('change', function() {
            toggleCustomRow(this.value);
            renderPreview(this.value);
            syncRemoveBtn();
        });

        uploadBtn.on('click', function(e) {
            e.preventDefault();

            if (uploader) {
                uploader.open();
                return;
            }

            uploader = wp.media({
                title: options.mediaTitle || 'Choose Icon',
                button: { text: options.mediaButton || 'Choose Icon' },
                multiple: false
            });

            uploader.on('select', function() {
                var attachment = uploader.state().get('selection').first().toJSON();
                urlInput.val(attachment.url);
                uploadBtn.text('Change Icon');
                syncRemoveBtn();
                renderPreview('custom');
            });

            uploader.open();
        });

        removeBtn.on('click', function(e) {
            e.preventDefault();
            urlInput.val('');
            uploadBtn.text('Upload Icon');
            syncRemoveBtn();
            renderPreview(typeField.val());
        });

        function toggleCustomRow(iconType) {
            if (!customRow.length) return;
            if (iconType === 'custom') {
                customRow.removeClass('n8n_union-hide').addClass('n8n_union-show').css('display', 'block');
            } else {
                customRow.removeClass('n8n_union-show').addClass('n8n_union-hide').hide();
            }
        }

        function syncRemoveBtn() {
            if (!removeBtn.length) return;
            if (urlInput.val()) {
                removeBtn.removeClass('n8n_union-hide').addClass('n8n_union-show').show();
            } else {
                removeBtn.removeClass('n8n_union-show').addClass('n8n_union-hide').hide();
            }
        }

        function renderPreview(iconType) {
            if (!previewContainer.length) return;
            previewContainer.empty();
            var customUrl = urlInput.val();

            if (iconType === 'custom' && customUrl) {
                previewContainer.html('<img src="' + customUrl + '" class="n8n_union-icon-preview-image">');
            } else if (iconType !== 'custom') {
                previewContainer.html(getPredefinedIconSvg(iconType));
            } else {
                previewContainer.html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="n8n_union-default-icon"><path d="M12,2A2,2 0 0,1 14,4C14,5.1 13.1,6 12,6C10.9,6 10,5.1 10,4A2,2 0 0,1 12,2M10.5,7.5L9,7H3V9H4.2C4.6,12 6.9,14.3 10,14.7V22H14V14.6C17.1,14.2 19.4,11.9 19.8,9H21V7H15L13.5,7.5C13.1,7.4 12.6,7.5 12,7.5C11.4,7.5 10.9,7.4 10.5,7.5Z"/></svg>');
            }
        }

        toggleCustomRow(typeField.val());
        renderPreview(typeField.val());
        syncRemoveBtn();
    }

    function getPredefinedIconSvg(iconType) {
        var icons = {
            'robot': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="n8n_union-predefined-icon"><path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2M21 9V7H15L13.5 7.5C13.1 7.4 12.6 7.5 12 7.5S10.9 7.4 10.5 7.5L9 7H3V9H4.2C4.6 12 6.9 14.3 10 14.7V19.5C10 20.6 10.4 21.6 11.2 22.2C11.7 22.7 12.4 23 13 23H14C14.6 23 15.2 22.8 15.7 22.4C16.5 21.8 17 20.8 17 19.7V14.6C20.1 14.2 22.4 11.9 22.8 9H24V7H21Z"/></svg>',
            'chat': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="n8n_union-predefined-icon"><path d="M12,3C17.5,3 22,6.58 22,11C22,15.42 17.5,19 12,19C10.76,19 9.57,18.82 8.47,18.5C5.55,21 2,21 2,21C4.33,18.67 4.7,17.1 4.75,16.5C3.05,15.07 2,13.13 2,11C2,6.58 6.5,3 12,3Z"/></svg>',
            'assistant': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="n8n_union-predefined-icon"><path d="M12,2A2,2 0 0,1 14,4A2,2 0 0,1 12,6A2,2 0 0,1 10,4A2,2 0 0,1 12,2M21,9V7L15,7.5V7.5C15,7.5 13.5,7 12,7C10.5,7 9,7.5 9,7.5L3,7V9H4.2C4.6,12 6.9,14.3 10,14.7V19.5L10.1,19.7C10.1,20.6 10.6,21.4 11.2,22C11.7,22.6 12.4,23 13,23H14C14.6,23 15.2,22.7 15.7,22.2C16.4,21.6 16.9,20.7 16.9,19.8L17,19.5V14.6C20.1,14.2 22.4,11.9 22.8,9H21Z"/></svg>',
            'support': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="n8n_union-predefined-icon"><path d="M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z"/></svg>',
            'default': '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="n8n_union-predefined-icon"><path d="M12,2A2,2 0 0,1 14,4C14,5.1 13.1,6 12,6C10.9,6 10,5.1 10,4A2,2 0 0,1 12,2M10.5,7.5L9,7H3V9H4.2C4.6,12 6.9,14.3 10,14.7V22H14V14.6C17.1,14.2 19.4,11.9 19.8,9H21V7H15L13.5,7.5C13.1,7.4 12.6,7.5 12,7.5C11.4,7.5 10.9,7.4 10.5,7.5Z"/></svg>'
        };
        return icons[iconType] || icons['default'];
    }

    initIconControl({
        typeSelector: '#n8n_union_chat_bot_icon_type',
        customRowSelector: '#custom_icon_row',
        urlSelector: '#n8n_union_chat_bot_icon_custom',
        uploadBtnSelector: '#upload_bot_icon_button',
        removeBtnSelector: '#remove_bot_icon_button',
        previewSelector: '#icon_preview',
        mediaTitle: 'Choose Bot Icon',
        mediaButton: 'Choose Icon'
    });

    initIconControl({
        typeSelector: '#n8n_union_chat_launcher_icon_type',
        customRowSelector: '#launcher_custom_icon_row',
        urlSelector: '#n8n_union_chat_launcher_icon_custom',
        uploadBtnSelector: '#upload_launcher_icon_button',
        removeBtnSelector: '#remove_launcher_icon_button',
        previewSelector: '#launcher_icon_preview',
        mediaTitle: 'Choose Launcher Icon',
        mediaButton: 'Use Icon'
    });
});
