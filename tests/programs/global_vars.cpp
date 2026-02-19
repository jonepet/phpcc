// expect: 3
int counter = 0;

void increment() {
    counter = counter + 1;
}

int main() {
    increment();
    increment();
    increment();
    return counter;  // 3
}
