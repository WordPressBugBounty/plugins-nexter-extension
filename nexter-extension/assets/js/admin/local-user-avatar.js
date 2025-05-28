'use strict';

// WordPress default media sizes
const WPMediaSizes = ['full', 'large', 'medium', 'thumbnail'];

// Get default Src and default SrcSet
const avatarUrl = nxt_local_avatar.avatarUrl;
const avatarSrcset = nxt_local_avatar.avatarSrcset;

/**
 * Update attachment
 */
function updateAttachment(attachmentSrc = '', attachmentSrcSet = '', attachmentId = null) {
    const avatar = document.querySelector('.nxt-attach-avatar');
    if (avatar) {
        avatar.setAttribute('src', attachmentSrc);
        avatar.setAttribute('srcset', attachmentSrcSet);
    }

    const input = document.querySelector('input[name="nxt_user_avatar_attach_id"]');
    if (input) {
        input.value = attachmentId === null ? '' : parseInt(attachmentId, 10);
    }

    const description = document.getElementById('nxt-attach-description');
    const removeBtn = document.getElementById('nxt-media-remove-btn');

    if (attachmentSrc === avatarUrl) {
        description?.classList.remove('hidden');
        removeBtn?.classList.remove('hidden');
    } else {
        description?.classList.toggle('hidden');
        removeBtn?.classList.toggle('hidden');
    }
}

/**
 * Init functions
 */
document.addEventListener('DOMContentLoaded', function() {
    // Place the new Profile Picture section
    const localAvatar = document.getElementById('local-user-avatar');
    const profilePicture = document.querySelector('.user-profile-picture');
    if (localAvatar && profilePicture) {
        profilePicture.insertAdjacentElement('afterend', localAvatar);
    }

    // Event delegation
    document.addEventListener('click', function(e) {
        const target = e.target;

        if (target.id === 'nxt-media-btn-add') {
            // Open WordPress Media Library
            wp.media.editor.open();

            // Change title and button text
            const titleElement = document.querySelector('.media-frame-title h1');
			if (titleElement) {
				titleElement.textContent = nxt_local_avatar.title;
			}

			const buttonElement = document.querySelector('.media-button-insert');
			if (buttonElement) {
				buttonElement.textContent = nxt_local_avatar.button;
			}

            wp.media.editor.send.attachment = function(props, attachment) {
                let attachmentSrc = attachment.url;

                for (const size of WPMediaSizes) {
                    if (attachment.sizes?.[size]?.url) {
                        attachmentSrc = attachment.sizes[size].url;
                    }
                }

                updateAttachment(attachmentSrc, attachmentSrc, attachment.id);
            };

            wp.Uploader.queue.on('reset', function() {
                const buttonElement = document.querySelector('.media-button-insert');
				if (buttonElement) {
					buttonElement.textContent = nxt_local_avatar.button;
				}
            });
        }

        if (target.id === 'nxt-media-remove-btn') {
            updateAttachment(avatarUrl, avatarSrcset);
            target.classList.add('hidden');
        }

        if (target.classList.contains('nxt-attach-avatar')) {
            document.getElementById('nxt-media-btn-add')?.click();
        }

        if (target.classList.contains('attachments-wrapper')) {
			const buttonElement = document.querySelector('.media-button-insert');
			if (buttonElement) {
				buttonElement.textContent = nxt_local_avatar.button;
			}
        }
    });
});