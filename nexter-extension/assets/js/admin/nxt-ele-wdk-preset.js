(function ($) {
    
    $("document").ready(function () {
        
        elementor.on("preview:loaded", function () {
        
            if (typeof ElementorConfig !== 'undefined' && ElementorConfig.elements) {
                if (Object.keys(ElementorConfig.initial_document.elements).length === 0) {
                    $(document).on("click", ".nxt-theme-preset-btn-enable-preset", function (e) {
                        e.preventDefault();

                        $(this).html("Installing WDesignKit");
                    
                        const formData = new FormData();
                        formData.append('action', 'nexter_ext_plugin_install');
                        formData.append('nexter_nonce', nxt_ele_wdkit.ajax_nonce);
                        formData.append('slug', 'wdesignkit');

                        $.ajax({
                            url: nxt_ele_wdkit.ajax_url,
                            type: "POST",
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function (response) {
                                if (response) {
                                    window.location.hash = window.location.hash + '?wdesignkit=open';
                                    window.location.reload();
                                } else {
                                    alert("Failed to Install, Please Manual Install Plugin. " + response.message);
                                }
                            },
                            error: function (xhr, status, error) {
                                console.error("Error cache.", error);
                            }
                        });
                    });

                    if(nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin != true){
                        window.tp_wdkit_editor = elementorCommon.dialogsManager.createWidget(
                            "lightbox",
                            {
                                id: "tp-wdkit-elementorp",
                                headerMessage: !1,
                                message: "",
                                hide: {
                                    auto: !1,
                                    onClick: !1,
                                    onOutsideClick: false,
                                    onOutsideContextMenu: !1,
                                    onBackgroundClick: !0,
                                },
                                position: {
                                    my: "center",
                                    at: "center",
                                },
                                onShow: function () {
                                    var dialogLightboxContent = $(".dialog-lightbox-message"),
                                        clonedWrapElement = $("#tp-wdkit-wrap");
                
                                    var htmlcode = "<div id='nxt-preset-btn-wrap' class='nxt-theme-preset-btn-main-container'><div class='nxt-theme-preset-btn-middel-sections'><div class='nxt-theme-preset-btn-text-top'>Import Pre-Designed Widgets Styles for Nexter Blocks</div><div class='nxt-theme-preset-btn-text-bottom'></div><div class='nxt-theme-preset-btn-cb-data'><div class='nxt-theme-preset-btn-preset-checkbox'><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg width='15' height='15' viewBox='0 0 10 10' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M5 0C2.24311 0 0 2.24311 0 5C0 7.75689 2.24311 10 5 10C7.75689 10 10 7.75689 10 5C10 2.24311 7.75689 0 5 0ZM7.79449 3.68421L4.599 6.85464C4.41103 7.04261 4.11028 7.05514 3.90977 6.86717L2.21804 5.32581C2.01754 5.13784 2.00501 4.82456 2.18045 4.62406C2.36842 4.42356 2.6817 4.41103 2.88221 4.599L4.22306 5.82707L7.0802 2.96992C7.2807 2.76942 7.59398 2.76942 7.79449 2.96992C7.99499 3.17043 7.99499 3.48371 7.79449 3.68421Z' fill='white' /></svg><p class='nxt-theme-preset-btn-preset-label'>Design Quickly without starting from Scratch</p></span><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg width='15' height='15' viewBox='0 0 10 10' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M5 0C2.24311 0 0 2.24311 0 5C0 7.75689 2.24311 10 5 10C7.75689 10 10 7.75689 10 5C10 2.24311 7.75689 0 5 0ZM7.79449 3.68421L4.599 6.85464C4.41103 7.04261 4.11028 7.05514 3.90977 6.86717L2.21804 5.32581C2.01754 5.13784 2.00501 4.82456 2.18045 4.62406C2.36842 4.42356 2.6817 4.41103 2.88221 4.599L4.22306 5.82707L7.0802 2.96992C7.2807 2.76942 7.59398 2.76942 7.79449 2.96992C7.99499 3.17043 7.99499 3.48371 7.79449 3.68421Z' fill='white' /></svg><p class='nxt-theme-preset-btn-preset-label'>Fully Customizable for Any Style</p></span></div><div class='nxt-theme-preset-btn-preset-checkbox'><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg width='15' height='15' viewBox='0 0 10 10' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M5 0C2.24311 0 0 2.24311 0 5C0 7.75689 2.24311 10 5 10C7.75689 10 10 7.75689 10 5C10 2.24311 7.75689 0 5 0ZM7.79449 3.68421L4.599 6.85464C4.41103 7.04261 4.11028 7.05514 3.90977 6.86717L2.21804 5.32581C2.01754 5.13784 2.00501 4.82456 2.18045 4.62406C2.36842 4.42356 2.6817 4.41103 2.88221 4.599L4.22306 5.82707L7.0802 2.96992C7.2807 2.76942 7.59398 2.76942 7.79449 2.96992C7.99499 3.17043 7.99499 3.48371 7.79449 3.68421Z' fill='white' /></svg><p class='nxt-theme-preset-btn-preset-label'>Time-Saving and Efficient Workflow</p></span><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg width='15' height='15' viewBox='0 0 10 10' fill='none' xmlns='http://www.w3.org/2000/svg'><path d='M5 0C2.24311 0 0 2.24311 0 5C0 7.75689 2.24311 10 5 10C7.75689 10 10 7.75689 10 5C10 2.24311 7.75689 0 5 0ZM7.79449 3.68421L4.599 6.85464C4.41103 7.04261 4.11028 7.05514 3.90977 6.86717L2.21804 5.32581C2.01754 5.13784 2.00501 4.82456 2.18045 4.62406C2.36842 4.42356 2.6817 4.41103 2.88221 4.599L4.22306 5.82707L7.0802 2.96992C7.2807 2.76942 7.59398 2.76942 7.79449 2.96992C7.99499 3.17043 7.99499 3.48371 7.79449 3.68421Z' fill='white' /> </svg><p class='nxt-theme-preset-btn-preset-label'>Explore Versatile Layout Options</p></span></div></div><div class='nxt-theme-preset-btn-preset-enable'><div class='nxt-theme-preset-btn-pink-btn nxt-theme-preset-btn-install'><span class='nxt-preset-btn-enable-text'><div class='nxt-theme-preset-btn-enable-preset'>Enable Presets</div></span><div class='nxt-theme-preset-btn-publish-loader'><div class='nxt-theme-preset-btn-loader-circle'></div></div></div></div></div><div class='nxt-theme-preset-btn-image-sections'></div>";
                                
                                    dialogLightboxContent.html(htmlcode);
                
                                    dialogLightboxContent.on("click", ".tp-close-btn", function () {
                                        window.tp_wdkit_editor.hide();
                                    });
                                },
                                onHide: function () {
                                    window.tp_wdkit_editor.destroy();
                                }
                            }
                        );
                        window.tp_wdkit_editor.show();
                    }
                }
            }
        
        });

        
    });
})(jQuery);
