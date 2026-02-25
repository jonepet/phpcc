    .text

    # ---------------------------------------------------------------------------
    # __cppc_alloc — wrapper for malloc
    # rdi: size in bytes
    # returns: pointer in rax
    # ---------------------------------------------------------------------------
    .globl __cppc_alloc
    .type __cppc_alloc, @function
__cppc_alloc:
    jmp malloc
    .size __cppc_alloc, .-__cppc_alloc

    # ---------------------------------------------------------------------------
    # __cppc_free — wrapper for free
    # rdi: pointer
    # ---------------------------------------------------------------------------
    .globl __cppc_free
    .type __cppc_free, @function
__cppc_free:
    jmp free
    .size __cppc_free, .-__cppc_free

    # ---------------------------------------------------------------------------
    # __cppc_print_int — print a signed 64-bit integer to stdout
    # rdi: integer value
    # ---------------------------------------------------------------------------
    .globl __cppc_print_int
    .type __cppc_print_int, @function
__cppc_print_int:
    pushq %rbp
    movq %rsp, %rbp
    subq $32, %rsp

    movq %rdi, %rax         # value to convert

    # Handle negative numbers
    testq %rax, %rax
    jge .Lpi_positive
    negq %rax
    movb $45, .Lpi_buf+0(%rip)
    movq $1, %r8            # sign flag: 1 = negative
    jmp .Lpi_convert
.Lpi_positive:
    movq $0, %r8

.Lpi_convert:
    # Convert integer to decimal string in .Lpi_buf, right-to-left
    leaq .Lpi_buf(%rip), %r9
    movq $20, %rcx          # max digits
    addq %rcx, %r9          # point past end of digit area
    movb $0, (%r9)          # null terminator
    movq $0, %r10           # digit count

.Lpi_digit_loop:
    movq $10, %rcx
    xorq %rdx, %rdx
    divq %rcx               # rax = quotient, rdx = remainder
    decq %r9
    addb $48, %dl
    movb %dl, (%r9)
    incq %r10
    testq %rax, %rax
    jnz .Lpi_digit_loop

    # If negative, prepend the minus sign
    testq %r8, %r8
    jz .Lpi_write
    decq %r9
    movb $45, (%r9)
    incq %r10

.Lpi_write:
    movq $1, %rdi           # stdout fd
    movq %r9, %rsi          # buffer pointer
    movq %r10, %rdx         # length
    movq $1, %rax           # sys_write
    syscall

    leaveq
    ret
    .size __cppc_print_int, .-__cppc_print_int

    # ---------------------------------------------------------------------------
    # __cppc_print_char — print a single character to stdout
    # rdi: character value (low byte used)
    # ---------------------------------------------------------------------------
    .globl __cppc_print_char
    .type __cppc_print_char, @function
__cppc_print_char:
    pushq %rbp
    movq %rsp, %rbp
    subq $16, %rsp

    movb %dil, -1(%rbp)     # store char on stack

    movq $1, %rdi           # stdout fd
    leaq -1(%rbp), %rsi     # pointer to char
    movq $1, %rdx           # length = 1
    movq $1, %rax           # sys_write
    syscall

    leaveq
    ret
    .size __cppc_print_char, .-__cppc_print_char

    # ---------------------------------------------------------------------------
    # __cppc_print_string — print a null-terminated string to stdout
    # rdi: pointer to null-terminated string
    # ---------------------------------------------------------------------------
    .globl __cppc_print_string
    .type __cppc_print_string, @function
__cppc_print_string:
    pushq %rbp
    movq %rsp, %rbp
    pushq %rbx

    movq %rdi, %rbx         # save string pointer

    # Compute strlen: scan for null byte
    movq %rbx, %rdi
    movq $0, %rcx
.Lps_strlen_loop:
    cmpb $0, (%rdi, %rcx, 1)
    je .Lps_strlen_done
    incq %rcx
    jmp .Lps_strlen_loop
.Lps_strlen_done:
    # rcx = length

    movq $1, %rdi           # stdout fd
    movq %rbx, %rsi         # string pointer
    movq %rcx, %rdx         # length
    movq $1, %rax           # sys_write
    syscall

    popq %rbx
    leaveq
    ret
    .size __cppc_print_string, .-__cppc_print_string

    # ---------------------------------------------------------------------------
    # __cxa_pure_virtual — called when a pure virtual function is invoked
    # ---------------------------------------------------------------------------
    .globl __cxa_pure_virtual
    .type __cxa_pure_virtual, @function
__cxa_pure_virtual:
    movq $2, %rdi           # stderr fd
    leaq .Lpure_msg(%rip), %rsi
    movq $27, %rdx          # message length
    movq $1, %rax           # sys_write
    syscall
    movq $1, %rdi
    movq $60, %rax          # sys_exit
    syscall
    .size __cxa_pure_virtual, .-__cxa_pure_virtual

    .section .rodata
.Lpure_msg:
    .asciz "pure virtual call detected\n"

    # Scratch buffer for __cppc_print_int (22 bytes: sign + 20 digits + null)
    .section .bss
    .align 8
.Lpi_buf:
    .skip 22
