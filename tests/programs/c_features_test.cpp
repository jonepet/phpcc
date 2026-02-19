// expect: 0
// toolchain: true
extern "C" {
    int printf(const char* fmt, ...);
    void* malloc(unsigned long size);
    void free(void* ptr);
}

// Forward declaration
struct Node;

// Struct with self-reference and pointer member
struct Node {
    int value;
    struct Node* next;
};

// Typedef
typedef struct Node Node;
typedef int (*Comparator)(int, int);

// Enum
enum Color { RED = 0, GREEN = 1, BLUE = 2 };

// Function pointer usage
int compare_asc(int a, int b) { return a - b; }

// String concatenation
const char* get_greeting() {
    return "Hello, "
           "World!";
}

// Compound type specifiers
unsigned long get_big() { return 1000; }

int main() {
    // Linked list
    Node* head = (Node*)malloc(16);
    Node* second = (Node*)malloc(16);
    head->value = 10;
    head->next = second;
    second->value = 20;
    second->next = 0;

    // Walk the list
    int sum = 0;
    Node* curr = head;
    while (curr != 0) {
        sum = sum + curr->value;
        curr = curr->next;
    }
    printf("sum=%d\n", sum);

    // Enum
    enum Color c = GREEN;
    printf("color=%d\n", c);

    // Function pointer typedef
    Comparator cmp = compare_asc;
    printf("cmp=%d\n", cmp(10, 3));

    // String concat
    printf("%s\n", get_greeting());

    // Compound types
    unsigned long big = get_big();
    printf("big=%lu\n", big);

    // Multiple declarators
    int x = 1, y = 2, z = 3;
    printf("xyz=%d\n", x + y + z);

    // Ternary
    int abs = sum >= 0 ? sum : -sum;
    printf("abs=%d\n", abs);

    // Compound assignment
    int acc = 10;
    acc += 5;
    acc -= 2;
    acc *= 3;
    printf("acc=%d\n", acc);

    // Cleanup
    free(head);
    free(second);

    return 0;
}
