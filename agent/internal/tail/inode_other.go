//go:build !linux

package tail

import "os"

// Non-Linux builds (development on macOS): no inode tracking; rotation is
// detected via size shrink only.
func inodeOf(_ os.FileInfo) uint64 { return 0 }
