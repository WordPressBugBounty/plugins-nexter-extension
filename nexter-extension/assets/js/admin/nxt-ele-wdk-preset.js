(function ($) {
    let wdkActive = nxt_ele_wdkit?.wdkactivate || false;
let btntext;
if(wdkActive) {
    btntext = 'Activate WDesignKit to Import Templates';
} else {
    btntext = 'Install WDesignKit to Import Templates';
}

let htmlcode = `<div class='nxt-wdk-overlay'></div><div id='nxt-preset-btn-wrap' class='nxt-theme-preset-btn-main-container'><div class='nxt-theme-preset-btn-middel-sections'><div class='nxt-theme-preset-header'><a href='https://wdesignkit.com/' target='_blank'><svg xmlns='http://www.w3.org/2000/svg' width='150' height='30' fill='none' viewBox='0 0 150 30'><path fill='#c22076' d='M36 13.463 24.518 27 18 21.039 11.482 27 0 13.463l6.518-5.961 7.146 8.414V3h8.672v12.916l7.146-8.414 4.156 3.788z'/><path fill='#040483' d='M44.176 24v-3.312h4.2q1.536 0 2.688-.6a4.3 4.3 0 0 0 1.776-1.8q.624-1.176.624-2.784t-.648-2.76a4.3 4.3 0 0 0-1.776-1.776q-1.128-.624-2.664-.624h-4.32V7.056h4.368q1.92 0 3.528.624 1.632.6 2.832 1.752a7.55 7.55 0 0 1 1.872 2.688q.672 1.536.672 3.408 0 1.848-.672 3.408a7.7 7.7 0 0 1-1.848 2.688 8.5 8.5 0 0 1-2.832 1.752Q50.368 24 48.472 24zm-2.544 0V7.056H45.4V24zm23.518.264q-1.896 0-3.384-.768a5.94 5.94 0 0 1-2.304-2.184q-.84-1.392-.84-3.144t.816-3.12a6.03 6.03 0 0 1 2.256-2.184q1.416-.792 3.192-.792 1.728 0 3.048.744a5.33 5.33 0 0 1 2.064 2.064q.768 1.32.768 3.024 0 .312-.048.672a6 6 0 0 1-.12.792l-10.056.024v-2.52l8.496-.024-1.584 1.056q-.024-1.008-.312-1.656-.288-.672-.864-1.008-.552-.36-1.368-.36-.864 0-1.512.408a2.65 2.65 0 0 0-.984 1.104q-.336.72-.336 1.752t.36 1.776q.384.72 1.056 1.128.696.384 1.632.384.864 0 1.56-.288a3.56 3.56 0 0 0 1.224-.912l2.016 2.016a5.44 5.44 0 0 1-2.088 1.512q-1.224.504-2.688.504m11.636.024a8 8 0 0 1-3.888-1.008 6.5 6.5 0 0 1-1.44-1.152l2.088-2.112q.576.624 1.368.984a4.4 4.4 0 0 0 1.728.336q.648 0 .984-.192.36-.192.36-.528 0-.432-.432-.648-.408-.24-1.056-.408l-1.368-.408a6 6 0 0 1-1.368-.6 3.03 3.03 0 0 1-1.056-1.056q-.408-.696-.408-1.752 0-1.128.576-1.944.576-.84 1.632-1.32t2.472-.48q1.488 0 2.736.528 1.272.504 2.064 1.512l-2.088 2.112q-.552-.648-1.248-.912a3.6 3.6 0 0 0-1.32-.264q-.624 0-.936.192a.54.54 0 0 0-.312.504q0 .36.408.576t1.056.384 1.368.408 1.368.648 1.056 1.104q.408.672.408 1.776 0 1.704-1.296 2.712-1.272 1.008-3.456 1.008M83.53 24V12.336h3.672V24zm1.848-13.272q-.864 0-1.44-.576-.552-.6-.552-1.44 0-.864.552-1.44.576-.576 1.44-.576t1.416.576.552 1.44q0 .84-.552 1.44-.552.576-1.416.576m9.354 18.504q-1.92 0-3.384-.672-1.44-.648-2.28-1.848l2.256-2.256q.624.744 1.392 1.128.792.384 1.896.384 1.368 0 2.136-.672.792-.672.792-1.896v-2.976l.624-2.544-.552-2.544v-3h3.6v10.968q0 1.8-.84 3.12t-2.304 2.064-3.336.744m-.168-5.592q-1.608 0-2.856-.768a5.5 5.5 0 0 1-1.968-2.088q-.72-1.32-.72-2.928 0-1.632.72-2.928a5.3 5.3 0 0 1 1.968-2.064q1.248-.768 2.856-.768 1.2 0 2.136.456.96.432 1.536 1.248.6.792.672 1.848v4.44a3.46 3.46 0 0 1-.672 1.848 3.96 3.96 0 0 1-1.536 1.248q-.96.456-2.136.456m.696-3.264q.768 0 1.32-.336.576-.336.864-.888.312-.576.312-1.296t-.312-1.296a2.16 2.16 0 0 0-.864-.912q-.552-.336-1.32-.336-.744 0-1.32.336t-.888.912a2.7 2.7 0 0 0-.312 1.296q0 .672.312 1.272.312.576.864.912.576.336 1.344.336M111.508 24v-6.648q0-.912-.576-1.464-.552-.576-1.416-.576-.6 0-1.056.264-.456.24-.72.72a2.07 2.07 0 0 0-.264 1.056l-1.416-.696q0-1.368.6-2.4t1.656-1.584q1.08-.576 2.424-.576 1.296 0 2.28.624 1.008.6 1.584 1.608t.576 2.208V24zm-7.704 0V12.336h3.672V24z'/><path fill='#c22076' d='m128.88 24-6.864-8.832 6.648-8.112h4.656l-7.488 8.808v-1.536L133.56 24zm-10.248 0V7.056h3.768V24zm16.586 0V12.336h3.672V24zm1.848-13.272q-.864 0-1.44-.576-.552-.6-.552-1.44 0-.864.552-1.44.576-.576 1.44-.576t1.416.576.552 1.44q0 .84-.552 1.44-.552.576-1.416.576M143.132 24V7.512h3.672V24zm-2.64-8.544v-3.12h8.952v3.12z'/></svg></a><div class='nxt-theme-preset-close'><svg xmlns='http://www.w3.org/2000/svg' width='13' height='13' fill='none' viewBox='0 0 13 13'><path fill='gray' d='M11.85.183a.625.625 0 1 1 .883.884l-5.39 5.391 5.39 5.392.044.047a.625.625 0 0 1-.88.88l-.047-.043-5.392-5.392-5.391 5.392a.625.625 0 1 1-.884-.884l5.392-5.392L.182 1.067a.625.625 0 1 1 .884-.884l5.391 5.392z'/></svg></div></div><div class='nxt-theme-preset-btn-text-top'>Import Pre-Designed Templates for Theme Builder</div><div class='nxt-theme-preset-btn-text-bottom'></div><div class='nxt-theme-preset-btn-cb-data'><div class='nxt-theme-preset-btn-preset-checkbox'><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg xmlns='http://www.w3.org/2000/svg' width='11' height='9' fill='none' viewBox='0 0 11 9'><path fill='gray' d='M9.471.155a.5.5 0 0 1 .724.69l-7 7.333a.5.5 0 0 1-.715.009L.146 5.853a.5.5 0 0 1 .67-.741l.037.034 1.972 1.972z'/></svg><p class='nxt-theme-preset-btn-preset-label'>Build your website faster with ready-to-use templates</p></span><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg xmlns='http://www.w3.org/2000/svg' width='11' height='9' fill='none' viewBox='0 0 11 9'><path fill='gray' d='M9.471.155a.5.5 0 0 1 .724.69l-7 7.333a.5.5 0 0 1-.715.009L.146 5.853a.5.5 0 0 1 .67-.741l.037.034 1.972 1.972z'/></svg><p class='nxt-theme-preset-btn-preset-label'>Fully customizable to match your brand and style</p></span></div><div class='nxt-theme-preset-btn-preset-checkbox'><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg xmlns='http://www.w3.org/2000/svg' width='11' height='9' fill='none' viewBox='0 0 11 9'><path fill='gray' d='M9.471.155a.5.5 0 0 1 .724.69l-7 7.333a.5.5 0 0 1-.715.009L.146 5.853a.5.5 0 0 1 .67-.741l.037.034 1.972 1.972z'/></svg><p class='nxt-theme-preset-btn-preset-label'>Save time by avoiding repetitive design work</p></span><span class='nxt-theme-preset-btn-preset-checkbox-content'><svg xmlns='http://www.w3.org/2000/svg' width='11' height='9' fill='none' viewBox='0 0 11 9'><path fill='gray' d='M9.471.155a.5.5 0 0 1 .724.69l-7 7.333a.5.5 0 0 1-.715.009L.146 5.853a.5.5 0 0 1 .67-.741l.037.034 1.972 1.972z'/></svg><p class='nxt-theme-preset-btn-preset-label'>Flexible layouts for headers, footers, pages, and more</p></span></div></div><div class='nxt-theme-preset-btn-preset-enable'><div class='nxt-theme-preset-btn-pink-btn nxt-theme-preset-btn-install'><span class='nxt-preset-btn-enable-text'><div class='nxt-theme-preset-btn-enable-preset'>${btntext}</div></span><div class='nxt-theme-preset-btn-publish-loader'><div class='nxt-theme-preset-btn-loader-circle'></div></div></div><a href="#" class="nxt-learn-more nxt-design-scratch">Design from Scratch</a> </div></div><div class='nxt-theme-preset-btn-image-sections'></div>`; 

    
    $(window).on('elementor:init', function () {

        elementor.on('preview:loaded', function () {
            const checkReady = setInterval(() => {
                const iframe = elementor.$preview[0];
                if (!iframe || !iframe.contentWindow) return;

                const iframeWindow = iframe.contentWindow;
                const isReady =
                    iframeWindow.elementorFrontend &&
                    iframeWindow.elementorFrontend.isEditMode &&
                    iframeWindow.elementorFrontend.isEditMode();

                if (isReady) {
                    clearInterval(checkReady);
                    if (typeof ElementorConfig !== 'undefined' && ElementorConfig.elements) {
                        if (Object.keys(ElementorConfig.initial_document.elements).length === 0) {
                            
                            $(document).on("click", ".nxt-theme-preset-btn-enable-preset", function (e) {
                                e.preventDefault();
                                if(nxt_ele_wdkit.wdkactivate){
                                    $(this).html("Activating WDesignKit");
                                }else{
                                    $(this).html("Installing WDesignKit");
                                }
                            
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
                                            //window.location.hash = window.location.hash + '?wdesignkit=open';
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

                            if(nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin != '1'){
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
                        
                                            
                                            dialogLightboxContent.html(htmlcode);
                        
                                            dialogLightboxContent.on("click", ".nxt-theme-preset-close, .nxt-wdk-overlay, .nxt-design-scratch", function () {
                                                window.tp_wdkit_editor.hide();
                                            });
                                        },
                                        onHide: function () {
                                            window.tp_wdkit_editor.destroy();
                                        }
                                    }
                                );
                                window.tp_wdkit_editor.show();
                            }else if(nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin == '1'){
                                let wdkID = 1018
                                if(nxt_ele_wdkit.layout_type== 'header'){
                                    wdkID = 1017
                                }else if(nxt_ele_wdkit.layout_type== 'footer'){
                                    wdkID = 1018
                                }else if(nxt_ele_wdkit.layout_type== 'breadcrumb'){
                                    wdkID = 1017
                                }else if(nxt_ele_wdkit.layout_type== 'section'){
                                    wdkID = 1002
                                }else if(nxt_ele_wdkit.layout_type== 'singular'){
                                    wdkID = 1009
                                }else if(nxt_ele_wdkit.layout_type== 'archives'){
                                    wdkID = 1010
                                }else if(nxt_ele_wdkit.layout_type== 'hooks'){  
                                    wdkID = 1002
                                }else if(nxt_ele_wdkit.layout_type== 'page-404'){  
                                    wdkID = 1012
                                }
                                window.WdkitThemeBuilderToggle.open(null, null, wdkID, '1001');
                            }
                        }
                    }
                }
            }, 1200);
        });
    });

    window.addEventListener("load", function () {
        var wdk_btn = document.querySelector("#nxt-import-wdk-template-button");

        // Open the popup if on "nxt_builder" post type page editor
        if (document.body.classList.contains("post-type-nxt_builder") && pagenow == 'nxt_builder' && adminpage == 'post-php') {
            let popIsLoaded = false;
            wp.data.subscribe(function () {
                setTimeout(() => {
                    const headerToolBar = document.querySelector(".editor-header__center");
                    if(headerToolBar){
                        if (headerToolBar.querySelector('#nexter-edit-condition') && !popIsLoaded) {
                            popIsLoaded = true;
                            const postContent = wp.data.select('core/editor').getEditedPostContent();
                            if (!postContent || postContent.trim() === '') {
                                openPopup();
                            }
                        }
                    }
                }, 5);
            });
        }

        if(wdk_btn){
            wdk_btn.addEventListener("click", function (e) {
                e.preventDefault(); // Prevent default button action
                if(nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin != '1'){
                    openPopup();
                } else if(nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin == '1'){
                    let wdkID =getWdkIDBasedOnLayout();
                    window.WdkitThemeBuilderToggle.open(null, null, wdkID);
                }
            });
        }

        // Function to open the popup
        function openPopup() {
            if (nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin != '1') {
                const newDiv = document.createElement("div");
                newDiv.className = "nxt-theme-builder-template-wrapper";
                newDiv.innerHTML = htmlcode;
                document.body.appendChild(newDiv);
                setupPopupEvents();
            }/*  else if (nxt_ele_wdkit && nxt_ele_wdkit.wdkPlugin == '1') {
                let wdkID = getWdkIDBasedOnLayout();
                window.WdkitThemeBuilderToggle.open(null, null, wdkID, '1001');
            } */
        }

        // Function to handle different layout types and set WDK ID
        function getWdkIDBasedOnLayout() {
            let wdkID = 1017;
            switch (nxt_ele_wdkit.layout_type) {
                case 'header':
                    wdkID = 1017;
                    break;
                case 'footer':
                    wdkID = 1018;
                    break;
                case 'breadcrumb':
                    wdkID = 1017;
                    break;
                case 'section':
                    wdkID = 1002;
                    break;
                case 'singular':
                    wdkID = 1009;
                    break;
                case 'archives':
                    wdkID = 1010;
                    break;
                case 'hooks':
                    wdkID = 1002;
                    break;
                case 'page-404':
                    wdkID = 1012;
                    break;
            }
            return wdkID;
        }

        // Function to attach events for popup interactions
        function setupPopupEvents() {
            $(document).on("click", ".nxt-theme-preset-btn-enable-preset", function (e) {
                e.preventDefault();
                if(nxt_ele_wdkit.wdkactivate){
                    $(this).html("Activating WDesignKit");
                }else{
                    $(this).html("Installing WDesignKit");
                }

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
                            //window.location.hash = window.location.hash + '?wdesignkit=open';
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

            $(document).on("click", ".nxt-wdk-overlay,.nxt-theme-preset-close,.nxt-design-scratch", function (e) {
                e.preventDefault();
                var temp_wrap = document.querySelector(".nxt-theme-builder-template-wrapper");
                if(temp_wrap) {
                    temp_wrap.remove();
                }
            });
        }
    });
})(jQuery);