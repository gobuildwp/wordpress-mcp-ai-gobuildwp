/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Block Editor Queue runtime (see gutenberg-finalizer.php): loads a hidden block
 * editor in an iframe so WordPress' own serializer turns agent-supplied blocks
 * into post content. Kept as readable source with no build step.
 *
 * Data: window.wppilotGutenbergFinalizer = { nonce }.
 */
( function () {
    const config = window.wppilotGutenbergFinalizer || {};
    const rootId = config.rootId || 'wppilot-gb-finalizer';
    const root = document.getElementById( rootId );
    if ( ! root || ! window.wp || ! wp.apiFetch ) {
        return;
    }

    const apiFetch = wp.apiFetch;
    apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );

    const progress = document.getElementById( 'wppilot-gb-progress' );
    const notice = document.getElementById( 'wppilot-gb-notice' );
    let editorFrame = document.getElementById( 'wppilot-gb-editor-frame' );
    const editorLoadTimeoutMs = Number( config.editorLoadTimeoutMs || 30000 );
    const blockRegistrationTimeoutMs = Number( config.blockRegistrationTimeoutMs || 30000 );
    const blockMountTimeoutMs = Number( config.blockMountTimeoutMs || 8000 );
    let leaseOwner = '';
    let isRunning = false;
    let dashboardPollRunning = false;
    let editorFrameUrl = '';
    let editorFrameLoadPromise = Promise.resolve();
    let frameAccessError = null;
    let fallbackWarning = '';
    let mountWarning = '';
    let usingFallbackRuntime = false;

    const path = ( suffix ) => `/wppilot/v1${ suffix }`;

    const setNotice = ( type, message ) => {
        if ( ! notice ) {
            return;
        }
        notice.className = `notice notice-${ type }`;
        notice.hidden = false;
        const p = notice.querySelector( 'p' );
        if ( p ) {
            p.textContent = message;
        }
    };

    const clearNotice = () => {
        if ( notice ) {
            notice.hidden = true;
        }
    };

    const setProgress = ( message ) => {
        if ( progress ) {
            progress.textContent = message;
        }
    };

    const issueMessage = ( issue ) => {
        if ( ! issue ) {
            return 'Block validation failed.';
        }
        if ( typeof issue === 'string' ) {
            return issue;
        }
        if ( issue.message ) {
            return issue.message;
        }
        if ( Array.isArray( issue.args ) ) {
            return issue.args.map( String ).join( ' ' );
        }
        try {
            return JSON.stringify( issue );
        } catch ( error ) {
            return 'Block validation failed.';
        }
    };

    const compactIssue = ( validation, issue ) => ( {
        block_name: validation.name || '',
        path: validation.path || '',
        category: 'validation',
        code: 'block_validation_failed',
        message: issueMessage( issue ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 ),
    } );

    const sleep = ( milliseconds ) => new Promise( ( resolve ) => {
        window.setTimeout( resolve, milliseconds );
    } );

    const sameOriginEditorUrl = ( editorUrl ) => {
        if ( ! editorUrl ) {
            throw new Error( 'The queued Gutenberg item did not include an editor URL.' );
        }

        const url = new URL( editorUrl, window.location.href );
        if ( url.origin !== window.location.origin ) {
            throw new Error( 'The editor iframe URL is not same-origin.' );
        }

        return url.href;
    };

    const ensureEditorFrame = () => {
        if ( editorFrame ) {
            return editorFrame;
        }

        const wrap = document.createElement( 'div' );
        wrap.className = 'wppilot-gb-editor-frame-wrap';
        wrap.setAttribute( 'aria-hidden', 'true' );
        wrap.style.position = 'absolute';
        wrap.style.top = '0';
        wrap.style.left = '-10000px';
        wrap.style.width = '1280px';
        wrap.style.height = '900px';
        wrap.style.overflow = 'hidden';
        wrap.style.opacity = '0';
        wrap.style.pointerEvents = 'none';

        const frame = document.createElement( 'iframe' );
        frame.id = 'wppilot-gb-editor-frame';
        frame.className = 'wppilot-gb-editor-frame';
        frame.title = 'GoBuildWP hidden block editor';
        frame.tabIndex = -1;
        frame.src = 'about:blank';
        frame.style.display = 'block';
        frame.style.width = '1280px';
        frame.style.height = '900px';
        frame.style.border = '0';

        wrap.appendChild( frame );
        root.appendChild( wrap );
        editorFrame = frame;

        return frame;
    };

    const navigateEditorFrame = ( editorUrl ) => {
        const frame = ensureEditorFrame();

        const nextUrl = sameOriginEditorUrl( editorUrl );
        if ( editorFrameUrl === nextUrl ) {
            return editorFrameLoadPromise;
        }

        editorFrameUrl = nextUrl;
        editorFrameLoadPromise = new Promise( ( resolve, reject ) => {
            let settled = false;
            const cleanup = () => {
                frame.removeEventListener( 'load', onLoad );
                window.clearTimeout( timeoutId );
            };
            const onLoad = () => {
                if ( settled ) {
                    return;
                }
                settled = true;
                cleanup();
                resolve();
            };
            const timeoutId = window.setTimeout( () => {
                if ( settled ) {
                    return;
                }
                settled = true;
                cleanup();
                reject( new Error( 'The hidden editor iframe did not finish loading.' ) );
            }, editorLoadTimeoutMs );

            frame.addEventListener( 'load', onLoad );
            frame.src = nextUrl;
        } );
        editorFrameLoadPromise.catch( () => {
            if ( editorFrameUrl === nextUrl ) {
                editorFrameUrl = '';
            }
        } );

        return editorFrameLoadPromise;
    };

    const iframeWindow = () => {
        if ( ! editorFrame || ! editorFrame.contentWindow ) {
            return null;
        }

        try {
            return editorFrame.contentWindow;
        } catch ( error ) {
            return null;
        }
    };

    const requiredBlocksMethods = [ 'createBlock', 'serialize', 'parse', 'validateBlock', 'getBlockType' ];

    const usableBlocksApi = ( blocksApi ) => {
        if ( ! blocksApi ) {
            return null;
        }

        const hasRequiredMethods = requiredBlocksMethods.every(
            ( method ) => typeof blocksApi[ method ] === 'function'
        );

        return hasRequiredMethods ? blocksApi : null;
    };

    // Every read below crosses a frame boundary, so any property access can throw a
    // SecurityError when the frame ends up in another origin. Treat that as "not ready"
    // and record it, so the caller can stop polling and explain what happened.
    const editorBlocksApi = () => {
        try {
            const frameWindow = iframeWindow();
            if ( ! frameWindow ) {
                return null;
            }

            const wpApi = frameWindow.wp;

            return wpApi ? usableBlocksApi( wpApi.blocks ) : null;
        } catch ( error ) {
            frameAccessError = error;
            return null;
        }
    };

    const crossOriginFrameError = ( error ) => {
        const detail = error && error.message ? ` (${ error.message })` : '';
        const frameError = new Error(
            'The hidden editor iframe loaded in a different origin, so its block editor runtime cannot be read. '
            + 'This usually means the editor URL redirected to another host or scheme, or the iframe was blocked '
            + `by an X-Frame-Options / Content-Security-Policy header, a proxy, or a browser extension.${ detail }`
        );
        frameError.code = 'editor_frame_cross_origin';
        return frameError;
    };

    const waitForEditorBlocksApi = async () => {
        const startedAt = Date.now();
        while ( Date.now() - startedAt < editorLoadTimeoutMs ) {
            frameAccessError = null;
            const blocksApi = editorBlocksApi();
            if ( blocksApi ) {
                return blocksApi;
            }
            // The frame already fired "load", so a blocked read will not resolve by waiting.
            if ( frameAccessError ) {
                throw crossOriginFrameError( frameAccessError );
            }
            await sleep( 100 );
        }

        throw new Error( 'The WordPress block editor JavaScript runtime is not available in the hidden iframe.' );
    };

    const collectBlockRefs = ( blocks, prefix = '' ) => {
        const refs = [];
        ( Array.isArray( blocks ) ? blocks : [] ).forEach( ( block, index ) => {
            if ( ! block || typeof block !== 'object' ) {
                return;
            }

            const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
            if ( typeof block.name === 'string' && block.name !== '' ) {
                refs.push( { name: block.name, path: pathText } );
            }
            refs.push( ...collectBlockRefs( block.innerBlocks || [], pathText ) );
        } );
        return refs;
    };

    const uniqueBlockNames = ( refs ) => Array.from( new Set( refs.map( ( ref ) => ref.name ) ) );

    const missingRegistrationError = ( missingRefs ) => {
        const names = uniqueBlockNames( missingRefs );
        const error = new Error( `The editor iframe did not register required block types: ${ names.join( ', ' ) }.` );
        error.code = 'missing_block_registration';
        error.missingBlockRefs = missingRefs;
        return error;
    };

    let coreBlocksRegistered = false;

    // Fallback runtime: this admin page loads wp-blocks and wp-block-library itself, so core
    // blocks can be serialized here when the hidden iframe is unreachable.
    const localBlocksApi = () => {
        const wpApi = window.wp;
        if ( ! wpApi ) {
            return null;
        }

        if ( ! coreBlocksRegistered ) {
            coreBlocksRegistered = true;
            if ( wpApi.blockLibrary && typeof wpApi.blockLibrary.registerCoreBlocks === 'function' ) {
                wpApi.blockLibrary.registerCoreBlocks();
            }
        }

        return usableBlocksApi( wpApi.blocks );
    };

    const waitForBlockRegistrations = async ( blocksApi, refs ) => {
        const startedAt = Date.now();
        let missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
        while ( missingRefs.length && Date.now() - startedAt < blockRegistrationTimeoutMs ) {
            await sleep( 100 );
            missingRefs = refs.filter( ( ref ) => ! blocksApi.getBlockType( ref.name ) );
        }

        if ( missingRefs.length ) {
            throw missingRegistrationError( missingRefs );
        }
    };

    const loadEditorBlocksApi = async ( editorUrl, blocks ) => {
        const refs = collectBlockRefs( blocks );
        let frameError = null;
        usingFallbackRuntime = false;

        try {
            await navigateEditorFrame( editorUrl );
            const blocksApi = await waitForEditorBlocksApi();
            await waitForBlockRegistrations( blocksApi, refs );
            return blocksApi;
        } catch ( error ) {
            frameError = error;
        }

        const fallbackApi = localBlocksApi();
        if ( ! fallbackApi ) {
            throw frameError;
        }

        const missingRefs = refs.filter( ( ref ) => ! fallbackApi.getBlockType( ref.name ) );
        if ( missingRefs.length ) {
            // Blocks the iframe would have registered, such as plugin or theme blocks, are
            // unavailable here. Report the missing names rather than the frame failure.
            throw frameError && frameError.code === 'missing_block_registration'
                ? frameError
                : missingRegistrationError( missingRefs );
        }

        usingFallbackRuntime = true;
        fallbackWarning = 'The hidden block editor iframe could not be used, so blocks were serialized with the '
            + 'block runtime of this page. Only blocks registered on this page are supported. Reason: '
            + ( frameError && frameError.message ? frameError.message : 'unknown.' );

        return fallbackApi;
    };

    // PHP encodes a block with no attributes as `[]`, and a block made with an array for
    // attributes behaves differently once the editor mounts it: a core/list given `[]`
    // replaced its items with one empty item, so every queued list saved as `<li></li>`.
    const toBlock = ( blocksApi, spec ) => blocksApi.createBlock(
        spec.name,
        spec.attributes && ! Array.isArray( spec.attributes ) ? spec.attributes : {},
        ( spec.innerBlocks || [] ).map( ( innerSpec ) => toBlock( blocksApi, innerSpec ) )
    );

    const blockName = ( block ) => block.name || block.blockName || '';

    const validateBlocks = ( blocksApi, blocks, prefix = '' ) => {
        const validations = [];
        blocks.forEach( ( block, index ) => {
            const pathText = prefix === '' ? String( index ) : `${ prefix }.${ index }`;
            let result;
            try {
                result = blocksApi.validateBlock( block );
            } catch ( error ) {
                result = [ false, [ { message: error.message || String( error ) } ] ];
            }
            const isValid = Array.isArray( result ) ? result[ 0 ] === true : result === true;
            const issues = Array.isArray( result ) ? ( result[ 1 ] || [] ) : [];
            validations.push( {
                name: blockName( block ),
                path: pathText,
                isValid,
                issues,
            } );
            if ( Array.isArray( block.innerBlocks ) && block.innerBlocks.length ) {
                validations.push( ...validateBlocks( blocksApi, block.innerBlocks, pathText ) );
            }
        } );
        return validations;
    };

    const editorDataApi = () => {
        try {
            const frameWindow = iframeWindow();
            const dataApi = frameWindow && frameWindow.wp ? frameWindow.wp.data : null;

            return dataApi
                && typeof dataApi.select === 'function'
                && typeof dataApi.dispatch === 'function'
                ? dataApi
                : null;
        } catch ( error ) {
            return null;
        }
    };

    // Several block libraries assign part of a block's attributes from the editor itself —
    // the per-block style id and layout markers they generate when the block mounts — and
    // createBlock() alone never triggers that, because nothing has rendered the block's
    // edit component. Serializing straight from createBlock() therefore saved markup that
    // rendered unstyled, or with a row's columns stacked, while the queue reported success.
    // Put the blocks through the frame's own block-editor store first and serialize what
    // the editor made of them instead.
    const mountBlocksInEditor = async ( created ) => {
        const dataApi = editorDataApi();
        if ( ! dataApi ) {
            return null;
        }

        const readBlocks = () => {
            try {
                const selector = dataApi.select( 'core/block-editor' );

                return selector && typeof selector.getBlocks === 'function' ? selector.getBlocks() : null;
            } catch ( error ) {
                return null;
            }
        };

        // Wait for the editor to have loaded the post first. Resetting blocks into a store
        // that is still initialising is worse than not resetting at all: the editor loads
        // the post's own content on top, and serializing that would replace the agent's
        // blocks with whatever the page already had.
        const postLoaded = () => {
            try {
                const editorSelector = dataApi.select( 'core/editor' );

                return !! editorSelector
                    && typeof editorSelector.getCurrentPostId === 'function'
                    && Number( editorSelector.getCurrentPostId() ) > 0;
            } catch ( error ) {
                return false;
            }
        };

        const readyBy = Date.now() + blockMountTimeoutMs;
        while ( ! postLoaded() && Date.now() < readyBy ) {
            await sleep( 100 );
        }

        if ( ! postLoaded() ) {
            return null;
        }

        try {
            const editorStore = dataApi.dispatch( 'core/block-editor' );
            if ( ! editorStore || typeof editorStore.resetBlocks !== 'function' || ! readBlocks() ) {
                return null;
            }

            editorStore.resetBlocks( created );
        } catch ( error ) {
            return null;
        }

        // What comes back out has to still be the blocks that went in. Anything else means
        // the editor replaced them — it finished loading the post late, or a plugin reset
        // the store — and serializing that would write the wrong content while reporting
        // success, which is the failure this whole step exists to end.
        const isStillOurs = ( blocks ) => Array.isArray( blocks )
            && blocks.length === created.length
            && blocks.every( ( block, index ) => blockName( block ) === blockName( created[ index ] ) );

        // Editor-assigned attributes arrive a render at a time, so wait for two identical
        // reads rather than a fixed delay: blocks that assign nothing settle on the first
        // pair, and a deep tree that assigns an id at every level still gets every level.
        let previous = null;
        const deadline = Date.now() + blockMountTimeoutMs;
        while ( Date.now() < deadline ) {
            await sleep( 150 );
            const blocks = readBlocks();
            if ( ! blocks ) {
                return null;
            }

            if ( ! isStillOurs( blocks ) ) {
                return null;
            }

            const signature = JSON.stringify( blocks );
            if ( previous !== null && signature === previous ) {
                return blocks;
            }
            previous = signature;
        }

        const settled = readBlocks();
        if ( ! isStillOurs( settled ) ) {
            return null;
        }

        mountWarning = 'The hidden block editor was still changing the blocks after '
            + `${ Math.round( blockMountTimeoutMs / 1000 ) } seconds, so they were serialized as it last had them. `
            + 'Blocks that assign their own style ids on mount may be incomplete.';

        return settled;
    };

    const serializeJob = async ( job ) => {
        const blocks = job.blocks || [];
        const blocksApi = await loadEditorBlocksApi( job.editor_url || '', blocks );
        const created = blocks.map( ( spec ) => toBlock( blocksApi, spec ) );
        // The fallback runtime is this page's own wp.blocks, not the frame's, so its blocks
        // must not be handed to the frame's store.
        const mounted = usingFallbackRuntime ? null : await mountBlocksInEditor( created );
        const content = blocksApi.serialize( mounted || created );
        const parsed = blocksApi.parse( content );
        const validations = validateBlocks( blocksApi, parsed );
        const errors = [];
        validations.forEach( ( validation ) => {
            if ( validation.isValid ) {
                return;
            }
            const issues = validation.issues.length ? validation.issues : [ { message: 'Block validation failed.' } ];
            issues.forEach( ( issue ) => errors.push( compactIssue( validation, issue ) ) );
        } );
        return { content, validations, errors };
    };

    const failCurrentItem = async ( itemId, errors, message ) => apiFetch( {
        path: path( `/gutenberg/items/${ itemId }/fail` ),
        method: 'POST',
        data: {
            lease_owner: leaseOwner,
            errors,
            message,
        },
    } );

    const heartbeat = async () => apiFetch( {
        path: path( '/gutenberg/finalizer-runtime/heartbeat' ),
        method: 'POST',
    } );

    const finalNotice = ( batch ) => {
        if ( batch && batch.status === 'finalized' ) {
            const warnings = [ fallbackWarning, mountWarning ].filter( Boolean );
            if ( warnings.length ) {
                setNotice( 'warning', warnings.join( ' ' ) );
            } else {
                clearNotice();
            }
            setProgress( 'Nothing to do. The queue is ready.' );
            return;
        }

        setProgress( 'Something needs attention. Return to the agent.' );
        setNotice( 'error', 'Something needs attention. Return to the agent.' );
    };

    const processBatch = async ( batchId, initialClaim = null ) => {
        const activeBatchId = Number( batchId || 0 );
        if ( ! activeBatchId ) {
            return false;
        }
        if ( isRunning ) {
            return false;
        }

        isRunning = true;
        fallbackWarning = '';
        mountWarning = '';
        try {
            clearNotice();
            setProgress( 'Working on queued Gutenberg changes...' );
            const claim = initialClaim || await apiFetch( {
                path: path( `/gutenberg/batches/${ activeBatchId }/claim` ),
                method: 'POST',
            } );
            leaseOwner = claim.lease_owner;

            let processed = 0;
            const total = claim.batch && claim.batch.item_count ? claim.batch.item_count : 0;
            while ( true ) {
                const next = await apiFetch( {
                    path: path( `/gutenberg/batches/${ activeBatchId }/items/claim-next` ),
                    method: 'POST',
                    data: { lease_owner: leaseOwner },
                } );
                if ( next.done ) {
                    finalNotice( next.batch );
                    break;
                }

                const item = next.item;
                setProgress(
                    total > 1
                        ? `Working on queued Gutenberg changes (${ processed + 1 } of ${ total })...`
                        : 'Working on queued Gutenberg changes...'
                );
                const job = await apiFetch( {
                    path: path( `/gutenberg/items/${ item.item_id }/spec?lease_owner=${ encodeURIComponent( leaseOwner ) }` ),
                    method: 'GET',
                } );

                try {
                    const result = await serializeJob( job );
                    if ( result.errors.length ) {
                        await failCurrentItem( item.item_id, result.errors, 'JS validation failed; canonical content was not written.' );
                        setProgress( 'Something needs attention. Return to the agent.' );
                        setNotice( 'error', 'Something needs attention. Return to the agent.' );
                        break;
                    }

                    const completed = await apiFetch( {
                        path: path( `/gutenberg/items/${ item.item_id }/complete` ),
                        method: 'POST',
                        data: {
                            lease_owner: leaseOwner,
                            content: result.content,
                            validations: result.validations,
                        },
                    } );
                    processed += 1;
                    if ( completed.done ) {
                        finalNotice( completed.batch );
                        break;
                    }
                } catch ( error ) {
                    const isMissingRegistration = error && error.code === 'missing_block_registration';
                    const errorItems = isMissingRegistration && Array.isArray( error.missingBlockRefs )
                        ? error.missingBlockRefs.map( ( ref ) => ( {
                            block_name: ref.name || '',
                            path: ref.path || '',
                            category: 'registration',
                            code: 'missing_block_registration',
                            message: `Block "${ ref.name || '(missing name)' }" was not registered in the block editor runtime.`,
                        } ) )
                        : [ {
                            block_name: '',
                            path: '',
                            category: 'serialization',
                            code: ( error && error.code ) || 'js_exception',
                            message: error && error.message ? error.message : String( error ),
                        } ];
                    await failCurrentItem(
                        item.item_id,
                        errorItems,
                        isMissingRegistration
                            ? 'One or more Gutenberg blocks were not registered in the block editor runtime; canonical content was not written.'
                            : 'The browser block serializer threw an exception.'
                    );
                    setProgress( 'Something needs attention. Return to the agent.' );
                    setNotice( 'error', 'Something needs attention. Return to the agent.' );
                    break;
                }
            }
        } catch ( error ) {
            setNotice( 'error', 'The queue stopped. Return to the agent.' );
            setProgress( 'Something needs attention. Return to the agent.' );
            return false;
        } finally {
            isRunning = false;
        }

        return true;
    };

    const processDashboardQueue = async () => {
        if ( dashboardPollRunning || isRunning ) {
            return;
        }

        dashboardPollRunning = true;
        try {
            await heartbeat();
            const next = await apiFetch( {
                path: path( '/gutenberg/batches/claim-next' ),
                method: 'POST',
            } );
            if ( next.done || ! next.claim || ! next.claim.batch ) {
                clearNotice();
                setProgress( 'Nothing to do. The queue is ready.' );
                return;
            }

            clearNotice();
            setProgress( 'Working on queued Gutenberg changes...' );
            await processBatch( next.claim.batch.batch_id, next.claim );
        } catch ( error ) {
            setNotice( 'error', 'Queue disconnected. Reload this page.' );
            setProgress( 'Queue disconnected. Reload this page.' );
        } finally {
            dashboardPollRunning = false;
        }
    };

    heartbeat().catch( () => {} );
    window.setInterval( () => {
        heartbeat().catch( () => {
            setProgress( 'Queue disconnected. Reload this page.' );
        } );
    }, 15000 );

    window.setTimeout( processDashboardQueue, 250 );
    window.setInterval( processDashboardQueue, 5000 );
}() );
