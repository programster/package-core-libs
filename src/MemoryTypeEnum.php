<?php

/*
 * The different types of memory measurement that can be requested from Core::getMemory().
 *
 * Most cases map directly onto a field in /proc/meminfo, in which case the backing value is that field's name. A few
 * are calculated from several fields (e.g. USED, BUFF_CACHE and SWAP_USED, which match the columns of the Linux "free"
 * command), and their backing values are not meminfo field names.
 *
 * Not every field exists on every system. Which ones are present depends on the kernel version, its configuration, and
 * the architecture (e.g. HIGH_TOTAL only exists on 32-bit systems, and CMA_TOTAL is mostly seen on ARM). Requesting
 * one that doesn't exist on the current system results in an exception.
 *
 * Descriptions are based on https://docs.kernel.org/filesystems/proc.html#meminfo
 */

namespace Programster\CoreLibs;


enum MemoryTypeEnum : string
{
    # ---------------------------------------------------------------------------------------------------------------
    # The main figures (these match the columns of the "free" command)
    # ---------------------------------------------------------------------------------------------------------------

    # The total amount of usable RAM (physical RAM minus a few reserved bits and the kernel binary code).
    case TOTAL = 'MemTotal';

    # How much RAM can be given to new applications without swapping. This includes RAM that is currently being used
    # for disk caches, but that the kernel would free up if needed. This is usually the figure you want if you are
    # asking "how much memory is left?".
    case AVAILABLE = 'MemAvailable';

    # RAM that is completely unused. Linux uses spare RAM for disk caches, so this is usually much lower than
    # AVAILABLE, and is rarely the figure you want.
    case FREE = 'MemFree';

    # RAM that is in use and could not be freed up (TOTAL - AVAILABLE).
    case USED = 'Used';

    # RAM used by tmpfs and shared memory (the "shared" column of "free").
    case SHARED = 'Shmem';

    # RAM used by kernel buffers, the page cache, and reclaimable kernel slab memory (BUFFERS + CACHED + S_RECLAIMABLE).
    case BUFF_CACHE = 'BuffCache';

    # ---------------------------------------------------------------------------------------------------------------
    # Swap
    # ---------------------------------------------------------------------------------------------------------------

    # The total amount of swap space available.
    case SWAP_TOTAL = 'SwapTotal';

    # The amount of swap space that is currently unused.
    case SWAP_FREE = 'SwapFree';

    # The amount of swap space that is currently in use (SWAP_TOTAL - SWAP_FREE).
    case SWAP_USED = 'SwapUsed';

    # Memory that was swapped out, has been swapped back in, but is still also in the swapfile. If this memory is
    # needed again, it doesn't need to be swapped out again, saving I/O.
    case SWAP_CACHED = 'SwapCached';

    # Memory consumed by the zswap backend (the compressed size).
    case ZSWAP = 'Zswap';

    # The amount of anonymous memory stored in zswap (the original, uncompressed size).
    case ZSWAPPED = 'Zswapped';

    # ---------------------------------------------------------------------------------------------------------------
    # Caches and buffers
    # ---------------------------------------------------------------------------------------------------------------

    # Relatively temporary storage for raw disk blocks.
    case BUFFERS = 'Buffers';

    # In-memory cache for files read from the disk (the page cache), plus tmpfs and shmem. Doesn't include SWAP_CACHED.
    case CACHED = 'Cached';

    # ---------------------------------------------------------------------------------------------------------------
    # Active/inactive lists
    # ---------------------------------------------------------------------------------------------------------------

    # Memory that has been used more recently and usually not reclaimed unless absolutely necessary.
    case ACTIVE = 'Active';

    # Memory that has been less recently used. It is more eligible to be reclaimed for other purposes.
    case INACTIVE = 'Inactive';

    # Anonymous memory (not backed by a file) that has been used recently.
    case ACTIVE_ANON = 'Active(anon)';

    # Anonymous memory (not backed by a file) that has not been used recently, and can be swapped out.
    case INACTIVE_ANON = 'Inactive(anon)';

    # Page cache memory (backed by a file) that has been used recently.
    case ACTIVE_FILE = 'Active(file)';

    # Page cache memory (backed by a file) that has not been used recently, and can be reclaimed.
    case INACTIVE_FILE = 'Inactive(file)';

    # Memory that cannot be reclaimed (e.g. because it is locked).
    case UNEVICTABLE = 'Unevictable';

    # Memory locked with the mlock() system call. Mlocked memory is also unevictable.
    case MLOCKED = 'Mlocked';

    # ---------------------------------------------------------------------------------------------------------------
    # High/low memory (32-bit systems only)
    # ---------------------------------------------------------------------------------------------------------------

    # The total amount of "high memory", which is memory above ~860MB that the kernel can't directly address.
    case HIGH_TOTAL = 'HighTotal';

    # The amount of unused high memory.
    case HIGH_FREE = 'HighFree';

    # The total amount of "low memory", which the kernel can use for everything high memory can be used for, as well
    # as for its own data structures.
    case LOW_TOTAL = 'LowTotal';

    # The amount of unused low memory.
    case LOW_FREE = 'LowFree';

    # ---------------------------------------------------------------------------------------------------------------
    # Writeback
    # ---------------------------------------------------------------------------------------------------------------

    # Memory which is waiting to get written back to the disk.
    case DIRTY = 'Dirty';

    # Memory which is actively being written back to the disk.
    case WRITEBACK = 'Writeback';

    # Memory used by FUSE for temporary writeback buffers.
    case WRITEBACK_TMP = 'WritebackTmp';

    # Always zero on modern kernels. Previously counted NFS pages sent to the server but not yet committed to storage.
    case NFS_UNSTABLE = 'NFS_Unstable';

    # Memory used for block device "bounce buffers".
    case BOUNCE = 'Bounce';

    # ---------------------------------------------------------------------------------------------------------------
    # Page usage
    # ---------------------------------------------------------------------------------------------------------------

    # Non-file backed pages mapped into userspace page tables (i.e. memory that applications have allocated).
    case ANON_PAGES = 'AnonPages';

    # Files which have been mapped into memory (with mmap), such as libraries.
    case MAPPED = 'Mapped';

    # ---------------------------------------------------------------------------------------------------------------
    # Kernel memory
    # ---------------------------------------------------------------------------------------------------------------

    # Kernel allocations that the kernel will attempt to reclaim under memory pressure. Includes S_RECLAIMABLE.
    case K_RECLAIMABLE = 'KReclaimable';

    # In-kernel data structures cache (S_RECLAIMABLE + S_UNRECLAIM).
    case SLAB = 'Slab';

    # The part of SLAB that might be reclaimed, such as caches.
    case S_RECLAIMABLE = 'SReclaimable';

    # The part of SLAB that cannot be reclaimed under memory pressure.
    case S_UNRECLAIM = 'SUnreclaim';

    # Memory consumed by the kernel stacks of all tasks.
    case KERNEL_STACK = 'KernelStack';

    # Memory consumed by userspace page tables.
    case PAGE_TABLES = 'PageTables';

    # Memory consumed by secondary page tables (e.g. KVM's).
    case SEC_PAGE_TABLES = 'SecPageTables';

    # Memory consumed by the shadow call stacks of all tasks (only on kernels built with shadow call stack support).
    case SHADOW_CALL_STACK = 'ShadowCallStack';

    # Memory allocated to the percpu allocator used to back percpu allocations. Doesn't include the cost of metadata.
    case PERCPU = 'Percpu';

    # ---------------------------------------------------------------------------------------------------------------
    # Overcommit
    # ---------------------------------------------------------------------------------------------------------------

    # The total amount of memory currently available to be allocated on the system, based on the overcommit ratio.
    # This is only enforced if strict overcommit accounting is enabled (vm.overcommit_memory = 2).
    case COMMIT_LIMIT = 'CommitLimit';

    # The amount of memory presently allocated on the system, even if it has not been "used" by the processes yet.
    # This is often much larger than TOTAL, as applications commonly allocate more memory than they actually use.
    case COMMITTED_AS = 'Committed_AS';

    # ---------------------------------------------------------------------------------------------------------------
    # Virtual memory
    # ---------------------------------------------------------------------------------------------------------------

    # The total size of the vmalloc virtual address space. Note that this is address space, not physical memory, and
    # is enormous on 64-bit systems (e.g. 32 TiB).
    case VMALLOC_TOTAL = 'VmallocTotal';

    # The amount of the vmalloc area which is in use.
    case VMALLOC_USED = 'VmallocUsed';

    # The largest contiguous block of the vmalloc area which is free. Always zero on modern kernels.
    case VMALLOC_CHUNK = 'VmallocChunk';

    # ---------------------------------------------------------------------------------------------------------------
    # Hardware and firmware
    # ---------------------------------------------------------------------------------------------------------------

    # The amount of memory the kernel has identified as corrupted by hardware and stopped using.
    case HARDWARE_CORRUPTED = 'HardwareCorrupted';

    # Memory that has not been accepted by the guest yet (confidential computing virtual machines).
    case UNACCEPTED = 'Unaccepted';

    # The total amount of memory reserved for the Contiguous Memory Allocator (mostly seen on ARM devices).
    case CMA_TOTAL = 'CmaTotal';

    # The amount of the Contiguous Memory Allocator's memory that is unused.
    case CMA_FREE = 'CmaFree';

    # ---------------------------------------------------------------------------------------------------------------
    # Huge pages
    # ---------------------------------------------------------------------------------------------------------------

    # Non-file backed huge pages mapped into userspace page tables (transparent huge pages).
    case ANON_HUGE_PAGES = 'AnonHugePages';

    # Memory used by shared memory (shmem) and tmpfs allocated with huge pages.
    case SHMEM_HUGE_PAGES = 'ShmemHugePages';

    # Shared memory mapped into userspace with huge pages.
    case SHMEM_PMD_MAPPED = 'ShmemPmdMapped';

    # Memory used for filesystem data (page cache) allocated with huge pages.
    case FILE_HUGE_PAGES = 'FileHugePages';

    # Page cache mapped into userspace with huge pages.
    case FILE_PMD_MAPPED = 'FilePmdMapped';

    # The size of the pool of (non-transparent) huge pages. In /proc/meminfo this is a number of pages, but it is
    # converted to an amount of memory here by multiplying it by HUGE_PAGE_SIZE.
    case HUGE_PAGES_TOTAL = 'HugePages_Total';

    # The amount of the huge page pool that is not yet allocated. Converted from a number of pages, as above.
    case HUGE_PAGES_FREE = 'HugePages_Free';

    # The amount of the huge page pool that has been committed to but not yet allocated. Converted from a number of
    # pages, as above.
    case HUGE_PAGES_RESERVED = 'HugePages_Rsvd';

    # The amount of the huge page pool above the configured size ("surplus" pages). Converted from a number of pages,
    # as above.
    case HUGE_PAGES_SURPLUS = 'HugePages_Surp';

    # The size of a single (non-transparent) huge page. Usually 2 MiB.
    case HUGE_PAGE_SIZE = 'Hugepagesize';

    # The total amount of memory consumed by huge pages of all sizes.
    case HUGETLB = 'Hugetlb';

    # ---------------------------------------------------------------------------------------------------------------
    # Direct mapping of physical memory by the kernel (x86)
    # ---------------------------------------------------------------------------------------------------------------

    # The amount of memory the kernel has mapped with 4 KiB pages.
    case DIRECT_MAP_4K = 'DirectMap4k';

    # The amount of memory the kernel has mapped with 2 MiB pages.
    case DIRECT_MAP_2M = 'DirectMap2M';

    # The amount of memory the kernel has mapped with 4 MiB pages (32-bit systems without PAE only).
    case DIRECT_MAP_4M = 'DirectMap4M';

    # The amount of memory the kernel has mapped with 1 GiB pages.
    case DIRECT_MAP_1G = 'DirectMap1G';
}
